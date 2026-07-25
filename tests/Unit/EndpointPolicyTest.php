<?php

namespace Tests\Unit;

use App\Domain\Migration\EndpointPolicy;
use InvalidArgumentException;
use Tests\TestCase;

class EndpointPolicyTest extends TestCase
{
    public function test_it_canonicalizes_https_lan_endpoints(): void
    {
        self::assertSame(
            'https://10.20.30.40:8443/netbox',
            (new EndpointPolicy)->canonicalize(' https://10.20.30.40:8443/netbox/ '),
        );
    }

    public function test_it_rejects_credentials_queries_and_metadata_or_loopback_addresses(): void
    {
        foreach ([
            'https://user:pass@example.test',
            'https://example.test?token=secret',
            'https://127.0.0.1',
            'https://169.254.169.254',
            'https://[::1]',
            'https://100.100.100.200',
        ] as $url) {
            try {
                (new EndpointPolicy)->canonicalize($url);
                self::fail("{$url} should have been rejected.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_http_requires_an_explicit_development_override(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EndpointPolicy)->canonicalize('http://10.20.30.40');
    }

    public function test_only_the_exact_internal_sandbox_endpoint_may_use_http(): void
    {
        config()->set('ipamferry.sandbox_url', 'http://sandbox-netbox:8080');

        self::assertSame(
            'http://sandbox-netbox:8080',
            (new EndpointPolicy)->canonicalize('http://sandbox-netbox:8080/'),
        );

        foreach ([
            'http://sandbox-netbox',
            'http://sandbox-netbox:8081',
            'http://sandbox-netbox:8080/api',
            'http://another-service:8080',
        ] as $url) {
            try {
                (new EndpointPolicy)->canonicalize($url);
                self::fail("{$url} should have been rejected.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
