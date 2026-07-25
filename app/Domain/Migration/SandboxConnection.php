<?php

namespace App\Domain\Migration;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class SandboxConnection
{
    public function credentials(): array
    {
        $key = $this->secret((string) config('ipamferry.sandbox_api_key_file'), 12);
        $token = $this->secret((string) config('ipamferry.sandbox_api_token_file'), 40);

        return [
            'url' => (new EndpointPolicy)->canonicalize((string) config('ipamferry.sandbox_url')),
            'token' => "nbt_{$key}.{$token}",
        ];
    }

    public function available(): bool
    {
        if (! is_readable((string) config('ipamferry.sandbox_api_key_file'))
            || ! is_readable((string) config('ipamferry.sandbox_api_token_file'))
        ) {
            return false;
        }

        return Cache::remember('ipamferry:sandbox:available', 5, function (): bool {
            try {
                $credentials = $this->credentials();
                $response = Http::acceptJson()
                    ->withToken($credentials['token'])
                    ->withoutRedirecting()
                    ->connectTimeout(1)
                    ->timeout(2)
                    ->get(rtrim($credentials['url'], '/').'/api/status/');

                return $response->successful();
            } catch (Throwable) {
                return false;
            }
        });
    }

    private function secret(string $path, int $length): string
    {
        $value = is_readable($path) ? trim((string) file_get_contents($path)) : '';
        if (strlen($value) !== $length || ! ctype_alnum($value)) {
            throw new RuntimeException('The internal NetBox sandbox credentials are unavailable.');
        }

        return $value;
    }
}
