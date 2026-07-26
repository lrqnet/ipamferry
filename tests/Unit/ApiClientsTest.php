<?php

namespace Tests\Unit;

use App\Domain\Migration\NetBoxClient;
use App\Domain\Migration\PhpIpamClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiClientsTest extends TestCase
{
    public function test_phpipam_discovery_uses_official_read_endpoints_and_token_header(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($request->method() === 'OPTIONS') {
                return Http::response([
                    'success' => true,
                    'data' => ['permissions' => 'Read', 'controllers' => ['sections', 'subnets', 'addresses', 'vlan', 'vrf']],
                ], 200, ['phpipam-version' => '1.8.1', 'api-version' => '1.8']);
            }

            return Http::response(['success' => true, 'data' => str_ends_with($url, '/vrf/')
                ? [['vrfId' => '7', 'name' => 'Blue', 'rd' => '65000:7']]
                : []]);
        });

        $inventory = (new PhpIpamClient(
            'https://phpipam.example.test',
            'ipamferry',
            'phpipam-test-token',
        ))->inventory();

        self::assertSame('Blue', $inventory['objects']['vrfs'][0]['name']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://phpipam.example.test/api/ipamferry/vrf/');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/vrfs/'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://phpipam.example.test/api/ipamferry/circuits/');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://phpipam.example.test/api/ipamferry/circuits/providers/');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/tools/circuit'));
        self::assertSame([], $inventory['objects']['circuit_types']);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('phpipam-token', 'phpipam-test-token')
            && ! str_contains($request->url(), 'phpipam-test-token'));
    }

    public function test_netbox_discovery_follows_every_page_and_uses_v2_bearer_authentication(): void
    {
        config()->set('ipamferry.netbox_page_size', 1);
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_ends_with(parse_url($url, PHP_URL_PATH), '/api/status/')) {
                return Http::response([
                    'netbox-version' => '4.5.3',
                    'plugins' => [],
                ], 200, ['API-Version' => '4.5']);
            }
            if ($request->method() === 'OPTIONS') {
                return Http::response(['actions' => ['POST' => [
                    'name' => ['required' => true],
                ]]]);
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (str_ends_with(parse_url($url, PHP_URL_PATH), '/api/ipam/vrfs/')) {
                $offset = (int) ($query['offset'] ?? 0);

                return $offset === 0
                    ? Http::response(['results' => [['id' => 1, 'name' => 'Blue']], 'next' => 'next-page'])
                    : Http::response(['results' => [['id' => 2, 'name' => 'Red']], 'next' => null]);
            }

            return Http::response(['results' => [], 'next' => null]);
        });

        $inventory = (new NetBoxClient(
            'https://netbox.example.test',
            'nbt_test.key-secret',
        ))->inventory();

        self::assertCount(2, $inventory['objects']['vrfs']);
        self::assertTrue($inventory['write_schema']['vrf']['name']['required']);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer nbt_test.key-secret')
            && ! str_contains($request->url(), 'key-secret'));
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/api/ipam/vrfs/')
                && ($query['offset'] ?? null) === '1';
        });
    }

    public function test_netbox_legacy_tokens_use_the_legacy_authorization_scheme(): void
    {
        Http::fake([
            'https://netbox.example.test/api/status/' => Http::response(['netbox-version' => '3.7.8'], 200),
        ]);

        (new NetBoxClient('https://netbox.example.test', 'legacy-test-token'))->inspect();

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Token legacy-test-token'));
    }

    public function test_scoped_dcim_natural_keys_do_not_cross_sites_or_manufacturers(): void
    {
        $client = new NetBoxClient('https://netbox.example.test', 'nbt_test.key-secret');

        self::assertTrue($client->matchesNaturalKey('device', [
            'name' => 'edge-01',
            'site' => ['id' => 10],
        ], [
            'name' => 'EDGE-01',
            'site_id' => 10,
        ]));
        self::assertFalse($client->matchesNaturalKey('device', [
            'name' => 'edge-01',
            'site' => ['id' => 11],
        ], [
            'name' => 'edge-01',
            'site_id' => 10,
        ]));
        self::assertTrue($client->matchesNaturalKey('device_type', [
            'slug' => 'router-1000',
            'manufacturer' => ['id' => 20],
        ], [
            'slug' => 'router-1000',
            'manufacturer_id' => 20,
        ]));
        self::assertFalse($client->matchesNaturalKey('device_type', [
            'slug' => 'router-1000',
            'manufacturer' => ['id' => 21],
        ], [
            'slug' => 'router-1000',
            'manufacturer_id' => 20,
        ]));
    }
}
