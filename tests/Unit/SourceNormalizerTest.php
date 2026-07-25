<?php

namespace Tests\Unit;

use App\Domain\Migration\SourceNormalizer;
use Tests\TestCase;

class SourceNormalizerTest extends TestCase
{
    public function test_it_normalizes_dump_identifiers_ipv4_ipv6_and_network_addresses(): void
    {
        $inventory = [
            'instance' => ['fingerprint' => str_repeat('a', 64)],
            'objects' => [
                'vrfs' => [['vrfId' => '7', 'name' => 'Blue', 'rd' => '65000:7']],
                'l2domains' => [['id' => '8', 'name' => 'Campus']],
                'vlans' => [['vlanId' => '9', 'domainId' => '8', 'number' => '100', 'name' => 'Users']],
                'subnets' => [
                    ['id' => '10', 'subnet' => '167772259', 'mask' => '24', 'vrfId' => '7'],
                    ['id' => '11', 'subnet' => '42540766411282592856903984951653826560', 'mask' => '64', 'vrfId' => '7'],
                ],
                'addresses' => [
                    ['id' => '12', 'subnetId' => '10', 'ip_addr' => '167772161', 'state' => '2'],
                    ['id' => '13', 'subnetId' => '11', 'ip_addr' => '42540766411282592856903984951653826561', 'state' => '2'],
                ],
            ],
        ];

        $normalized = (new SourceNormalizer)->normalize($inventory);

        self::assertSame('7', $normalized['objects']['vrfs'][0]['source_id']);
        self::assertSame('9', $normalized['objects']['vlans'][0]['source_id']);
        self::assertSame('10.0.0.0/24', $normalized['objects']['prefixes'][0]['prefix']);
        self::assertSame('2001:db8::/64', $normalized['objects']['prefixes'][1]['prefix']);
        self::assertSame('10.0.0.1/24', $normalized['objects']['ip_addresses'][0]['address']);
        self::assertSame('2001:db8::1/64', $normalized['objects']['ip_addresses'][1]['address']);
        self::assertSame('7', $normalized['objects']['ip_addresses'][0]['vrf_source_id']);
    }

    public function test_it_preserves_unsupported_objects_without_secret_like_fields(): void
    {
        $normalized = (new SourceNormalizer)->normalize(['objects' => [
            'devices' => [[
                'id' => '1',
                'hostname' => 'router.example.test',
                'snmp_community' => 'private-community',
                'snmp_v3_auth_pass' => 'private-password',
                'api_key' => 'private-key',
                'description' => 'Edge router',
            ]],
        ]]);
        $device = $normalized['preserved']['devices'][0];

        self::assertSame('router.example.test', $device['hostname']);
        self::assertSame('Edge router', $device['description']);
        self::assertArrayNotHasKey('snmp_community', $device);
        self::assertArrayNotHasKey('snmp_v3_auth_pass', $device);
        self::assertArrayNotHasKey('api_key', $device);
        self::assertStringNotContainsString('private-', json_encode($normalized, JSON_THROW_ON_ERROR));
    }
}
