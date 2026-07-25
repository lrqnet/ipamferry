<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_locale_endpoint_persists_anonymous_cookie(): void
    {
        $this->put('/locale', ['locale' => 'pt_BR'])
            ->assertRedirect()
            ->assertCookie('ipamferry_locale', 'pt_BR');
    }

    public function test_locale_endpoint_persists_authenticated_preference(): void
    {
        $user = User::query()->create(['name' => 'Test User', 'email' => 'user@example.test', 'password' => 'password', 'role' => UserRole::Reader, 'locale' => 'en', 'is_active' => true]);

        $this->actingAs($user)->put('/locale', ['locale' => 'es'])->assertRedirect();

        self::assertSame('es', $user->refresh()->locale);
    }

    public function test_locale_endpoint_rejects_unsupported_locale(): void
    {
        $this->put('/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');
    }
}
