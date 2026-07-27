<?php

namespace Tests\Unit;

use App\Domain\Migration\SandboxConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SandboxConnectionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/sandbox-'.bin2hex(random_bytes(6)));
        mkdir($this->directory, 0700, true);

        config()->set('ipamferry.sandbox_url', 'http://sandbox-netbox:8080');
        config()->set('ipamferry.sandbox_api_key_file', "{$this->directory}/key");
        config()->set('ipamferry.sandbox_api_token_file', "{$this->directory}/token");
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->directory}/*") ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_it_builds_a_netbox_v2_token_only_from_valid_secret_files(): void
    {
        file_put_contents("{$this->directory}/key", str_repeat('k', 12)."\n");
        file_put_contents("{$this->directory}/token", str_repeat('x', 40)."\n");
        Http::fake(['http://sandbox-netbox:8080/api/status/' => Http::response(['netbox-version' => '4.6.1'])]);

        $connection = new SandboxConnection;

        self::assertTrue($connection->available());
        $token = implode('', ['n', 'b', 't', '_']).str_repeat('k', 12).'.'.str_repeat('x', 40);

        self::assertSame([
            'url' => 'http://sandbox-netbox:8080',
            'token' => $token,
        ], $connection->credentials());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader(
            'Authorization',
            'Bearer '.$token,
        ));
    }

    public function test_unreachable_sandbox_is_not_advertised(): void
    {
        file_put_contents("{$this->directory}/key", str_repeat('k', 12));
        file_put_contents("{$this->directory}/token", str_repeat('x', 40));
        Http::fake(['*' => Http::response([], 503)]);

        self::assertFalse((new SandboxConnection)->available());
    }

    public function test_it_uses_the_legacy_token_scheme_for_a_netbox_44_sandbox(): void
    {
        file_put_contents("{$this->directory}/key", str_repeat('k', 12));
        file_put_contents("{$this->directory}/token", str_repeat('x', 40));
        config()->set('ipamferry.sandbox_token_format', 'legacy');
        Http::fake(['http://sandbox-netbox:8080/api/status/' => Http::response(['netbox-version' => '4.4.10'])]);

        $connection = new SandboxConnection;

        self::assertSame(str_repeat('x', 40), $connection->credentials()['token']);
        self::assertTrue($connection->available());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader(
            'Authorization',
            'Token '.str_repeat('x', 40),
        ));
    }

    public function test_it_rejects_missing_or_malformed_internal_credentials(): void
    {
        file_put_contents("{$this->directory}/key", 'too-short');
        file_put_contents("{$this->directory}/token", str_repeat('*', 40));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('credentials are unavailable');

        (new SandboxConnection)->credentials();
    }
}
