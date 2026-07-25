<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetupSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_rejects_whitespace_and_malformed_email_addresses(): void
    {
        $this->from('/setup')->post('/setup', $this->payload(['email' => 'owner @example.test']))
            ->assertRedirect('/setup')
            ->assertSessionHasErrors('email');
    }

    public function test_setup_rejects_unsafe_name_and_token_values(): void
    {
        $this->from('/setup')->post('/setup', $this->payload(['name' => ' Owner  Name ', 'token' => 'token with spaces']))
            ->assertRedirect('/setup')
            ->assertSessionHasErrors(['name', 'token']);
    }

    public function test_setup_requires_a_strong_bounded_password(): void
    {
        $this->from('/setup')->post('/setup', $this->payload(['password' => 'ShortPwd1!', 'password_confirmation' => 'ShortPwd1!']))
            ->assertRedirect('/setup')
            ->assertSessionHasErrors('password');
    }

    public function test_login_rate_limiter_is_registered_and_valid_credentials_authenticate(): void
    {
        $user = User::query()->create([
            'name' => 'Owner Name',
            'email' => 'owner@example.test',
            'password' => Hash::make('LongerPassword1!'),
            'role' => 'owner',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'LongerPassword1!'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    /** @return array<string,string> */
    private function payload(array $overrides = []): array
    {
        return [...['token' => str_repeat('a', 48), 'name' => 'Owner Name', 'email' => 'owner@example.test', 'password' => 'LongerPassword1!', 'password_confirmation' => 'LongerPassword1!'], ...$overrides];
    }
}
