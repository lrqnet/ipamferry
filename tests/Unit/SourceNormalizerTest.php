<?php

namespace Tests\Unit;

use App\Domain\Migration\SourceNormalizer;
use App\Domain\Migration\SqlDumpParser;
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

    public function test_it_normalizes_supported_devices_without_secret_like_fields(): void
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
        $device = $normalized['objects']['devices'][0];

        self::assertSame('router.example.test', $device['name']);
        self::assertSame('Edge router', $device['description']);
        self::assertArrayNotHasKey('snmp_community', $device['legacy']);
        self::assertArrayNotHasKey('snmp_v3_auth_pass', $device['legacy']);
        self::assertArrayNotHasKey('api_key', $device['legacy']);
        self::assertStringNotContainsString('private-', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    public function test_it_derives_interfaces_valid_macs_and_preserves_portless_assignments(): void
    {
        $normalized = (new SourceNormalizer)->normalize(['objects' => [
            'devices' => [['id' => '1', 'hostname' => 'edge-01', 'type' => '10']],
            'subnets' => [['id' => '20', 'subnet' => '167772160', 'mask' => '24']],
            'addresses' => [
                ['id' => '30', 'subnetId' => '20', 'ip_addr' => '167772161', 'switch' => '1', 'port' => 'eth0', 'mac' => 'aa-bb-cc-dd-ee-ff'],
                ['id' => '31', 'subnetId' => '20', 'ip_addr' => '167772162', 'switch' => '1', 'port' => '', 'mac' => 'invalid'],
                ['id' => '32', 'subnetId' => '20', 'ip_addr' => '167772163', 'switch' => '1', 'port' => 'eth0', 'mac' => 'aa-bb-cc-dd-ee-ff'],
            ],
        ]]);

        self::assertCount(1, $normalized['objects']['interfaces']);
        self::assertCount(1, $normalized['objects']['mac_addresses']);
        self::assertSame('1:eth0', $normalized['objects']['interfaces'][0]['source_id']);
        self::assertSame('AA:BB:CC:DD:EE:FF', $normalized['objects']['mac_addresses'][0]['mac_address']);
        self::assertSame('1:eth0', $normalized['objects']['ip_addresses'][0]['interface_source_id']);
        self::assertNull($normalized['objects']['ip_addresses'][1]['interface_source_id']);
        self::assertSame('interface_missing', $normalized['preserved']['invalid_mac_addresses'][0]['reason']);
        self::assertStringContainsString('has no device port', implode("\n", $normalized['warnings']));
    }

    public function test_expanded_e2e_fixture_covers_every_supported_migration_domain(): void
    {
        $parser = new SqlDumpParser;
        $parsed = $parser->parseFile(base_path('tests/Fixtures/phpipam-expanded.sql'));
        $normalized = (new SourceNormalizer)->normalize([
            'objects' => $parser->toInventoryObjects($parsed),
        ]);
        $objects = $normalized['objects'];

        foreach ([
            'customers',
            'locations',
            'racks',
            'device_roles',
            'devices',
            'interfaces',
            'mac_addresses',
            'providers',
            'circuit_types',
            'circuits',
            'asns',
            'prefixes',
            'ip_addresses',
            'nat_relations',
        ] as $type) {
            self::assertNotEmpty($objects[$type], "Expanded fixture must include {$type}.");
        }
        self::assertSame('1:eth0', $objects['interfaces'][0]['source_id']);
        self::assertSame('101', $objects['nat_relations'][0]['inside_ip_source_id']);
        self::assertSame('102', $objects['nat_relations'][0]['outside_ip_source_id']);
        self::assertArrayHasKey('bgp_sessions', $normalized['preserved']);
    }
}
