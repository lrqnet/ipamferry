<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InstallationUpdate;
use App\Models\MigrationProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstallationUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ipamferry.version', '0.2.0');
        config()->set('ipamferry.updates_enabled', true);
        config()->set('ipamferry.release_api_url', 'https://updates.example.test/latest');
        Storage::fake('local');
    }

    public function test_only_an_owner_can_check_for_updates(): void
    {
        $reader = $this->user(UserRole::Reader);
        $this->actingAs($reader)->post('/installation-update/check')->assertForbidden();
    }

    public function test_owner_can_check_a_stable_release_without_downloading_it(): void
    {
        Http::fake(['https://updates.example.test/latest' => Http::response($this->release('0.2.1'), 200)]);

        $this->actingAs($this->user(UserRole::Owner))->post('/installation-update/check')->assertRedirect();

        $state = InstallationUpdate::query()->sole();
        self::assertSame('available', $state->status);
        self::assertSame('0.2.1', $state->available_version);
        Http::assertSentCount(1);
    }

    public function test_request_validates_checksum_and_queues_only_a_digest_pinned_compose(): void
    {
        $compose = "name: ipamferry\nservices:\n  app:\n    image: docker.io/lrqnet/ipamferry@sha256:".str_repeat('a', 64)."\n";
        Http::fake([
            'https://updates.example.test/latest' => Http::response($this->release('0.2.1'), 200),
            'https://downloads.example.test/compose.yaml' => Http::response($compose, 200),
            'https://downloads.example.test/compose.sha256' => Http::response(hash('sha256', $compose)."  compose.yaml\n", 200),
        ]);
        $owner = $this->user(UserRole::Owner);
        $this->actingAs($owner)->post('/installation-update/check');
        $this->actingAs($owner)->post('/installation-update')->assertRedirect();

        self::assertSame('requested', InstallationUpdate::query()->sole()->status);
        Storage::disk('local')->assertExists('private/updates/compose.yaml');
        self::assertStringNotContainsString('token', Storage::disk('local')->get('private/updates/request.json'));
    }

    public function test_request_is_blocked_while_a_migration_is_active(): void
    {
        $owner = $this->user(UserRole::Owner);
        InstallationUpdate::query()->create(['installed_version' => '0.2.0', 'status' => 'available', 'available_version' => '0.2.1']);
        MigrationProject::query()->create(['name' => 'Active', 'source_kind' => 'api', 'status' => 'applying', 'created_by' => $owner->id, 'locale' => 'en']);

        $this->actingAs($owner)->post('/installation-update')->assertSessionHas('error');
    }

    /** @return array<string, mixed> */
    private function release(string $version): array
    {
        return ['tag_name' => "v{$version}", 'draft' => false, 'prerelease' => false, 'html_url' => 'https://github.com/lrqnet/ipamferry/releases/tag/v'.$version, 'assets' => [
            ['name' => 'compose.yaml', 'browser_download_url' => 'https://downloads.example.test/compose.yaml'],
            ['name' => 'compose.sha256', 'browser_download_url' => 'https://downloads.example.test/compose.sha256'],
        ]];
    }

    private function user(UserRole $role): User
    {
        return User::query()->create(['name' => 'Test User', 'email' => $role->value.'@example.test', 'password' => 'password', 'role' => $role, 'locale' => 'en', 'is_active' => true]);
    }
}
