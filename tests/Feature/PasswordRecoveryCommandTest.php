<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordRecoveryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resets_the_only_active_owner_and_invalidates_authentication_state(): void
    {
        $owner = $this->user(['role' => UserRole::Owner, 'remember_token' => 'previous-token']);
        DB::table('sessions')->insert([
            'id' => 'active-session',
            'user_id' => $owner->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode('session'),
            'last_activity' => now()->timestamp,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $owner->email,
            'token' => Hash::make('previous-reset-token'),
            'created_at' => now(),
        ]);

        $this->artisan('ipamferry:reset-password')
            ->expectsConfirmation("Reset password for {$owner->email}?", 'yes')
            ->expectsQuestion('New password', 'AValidPassword1!')
            ->expectsQuestion('Confirm new password', 'AValidPassword1!')
            ->assertExitCode(0);

        $owner->refresh();
        $this->assertTrue(Hash::check('AValidPassword1!', $owner->password));
        $this->assertNotSame('AValidPassword1!', $owner->password);
        $this->assertNotSame('previous-token', $owner->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'active-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
        $event = SecurityEvent::query()->sole();
        $this->assertSame($owner->id, $event->user_id);
        $this->assertSame('password.reset', $event->kind);
        $this->assertSame('cli', $event->origin);
        $this->assertSame([], $event->context);
    }

    public function test_it_rejects_a_weak_password_without_changing_the_account(): void
    {
        $owner = $this->user(['role' => UserRole::Owner]);
        $originalHash = $owner->password;

        $this->artisan('ipamferry:reset-password', ['email' => $owner->email])
            ->expectsConfirmation("Reset password for {$owner->email}?", 'yes')
            ->expectsQuestion('New password', 'short')
            ->expectsQuestion('Confirm new password', 'short')
            ->assertExitCode(1);

        $this->assertSame($originalHash, $owner->fresh()->password);
        $this->assertSame(0, SecurityEvent::query()->count());
    }

    public function test_it_rejects_a_mismatched_password_confirmation(): void
    {
        $owner = $this->user(['role' => UserRole::Owner]);

        $this->artisan('ipamferry:reset-password', ['email' => $owner->email])
            ->expectsConfirmation("Reset password for {$owner->email}?", 'yes')
            ->expectsQuestion('New password', 'AValidPassword1!')
            ->expectsQuestion('Confirm new password', 'AnotherPassword1!')
            ->assertExitCode(1);
    }

    public function test_it_requires_an_exact_known_email_when_supplied(): void
    {
        $this->artisan('ipamferry:reset-password', ['email' => 'missing@example.test'])
            ->assertExitCode(1);
    }

    public function test_it_requires_an_email_when_multiple_active_owners_exist(): void
    {
        $this->user(['role' => UserRole::Owner, 'email' => 'one@example.test']);
        $this->user(['role' => UserRole::Owner, 'email' => 'two@example.test']);

        $this->artisan('ipamferry:reset-password')
            ->assertExitCode(1);
    }

    public function test_it_refuses_to_recover_an_inactive_account(): void
    {
        $user = $this->user(['is_active' => false]);

        $this->artisan('ipamferry:reset-password', ['email' => $user->email])
            ->assertExitCode(1);
    }

    public function test_it_requires_an_interactive_terminal(): void
    {
        $this->artisan('ipamferry:reset-password --no-interaction')
            ->assertExitCode(1);
    }

    public function test_fortify_does_not_register_email_password_reset_routes(): void
    {
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password')->assertNotFound();
    }

    private function user(array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Test User',
            'email' => 'owner@example.test',
            'password' => Hash::make('ExistingPassword1!'),
            'role' => UserRole::Reader,
            'is_active' => true,
        ], $attributes));
    }
}
