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
            'instance' => [
                'fingerprint' => str_repeat('a', 64),
                'permissions' => ['read' => true],
                'api_token' => 'must-not-survive',
            ],
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
        self::assertArrayNotHasKey('permissions', $normalized['instance']);
        self::assertArrayNotHasKey('api_token', $normalized['instance']);
    }

    public function test_it_normalizes_ipv4_and_ipv6_host_routes_point_to_point_prefixes_and_unicode_descriptions(): void
    {
        $description = "Comentários IPv6 — Bogotá\nsecond line with \"quotes\" & symbols";
        $normalized = (new SourceNormalizer)->normalize(['objects' => [
            'subnets' => [
                ['id' => 'v4-p2p', 'subnet' => '10.120.1.1', 'mask' => '31', 'description' => $description],
                ['id' => 'v4-host', 'subnet' => '10.120.1.2', 'mask' => '32'],
                ['id' => 'v6-p2p', 'subnet' => '2001:0DB8:0120:0001:0000:0000:0000:0001', 'mask' => '127'],
                ['id' => 'v6-host', 'subnet' => '2001:DB8:120:2::42', 'mask' => '128'],
            ],
            'addresses' => [
                ['id' => 'v4-p2p-ip', 'subnetId' => 'v4-p2p', 'ip_addr' => '10.120.1.1', 'description' => $description],
                ['id' => 'v4-host-ip', 'subnetId' => 'v4-host', 'ip_addr' => '10.120.1.2'],
                ['id' => 'v6-p2p-ip', 'subnetId' => 'v6-p2p', 'ip_addr' => '2001:DB8:120:1::1'],
                ['id' => 'v6-host-ip', 'subnetId' => 'v6-host', 'ip_addr' => '2001:db8:120:2::42'],
            ],
        ]]);

        self::assertSame([
            '10.120.1.0/31',
            '10.120.1.2/32',
            '2001:db8:120:1::/127',
            '2001:db8:120:2::42/128',
        ], array_column($normalized['objects']['prefixes'], 'prefix'));
        self::assertSame([
            '10.120.1.1/31',
            '10.120.1.2/32',
            '2001:db8:120:1::1/127',
            '2001:db8:120:2::42/128',
        ], array_column($normalized['objects']['ip_addresses'], 'address'));
        self::assertSame($description, $normalized['objects']['prefixes'][0]['description']);
        self::assertSame($description, $normalized['objects']['ip_addresses'][0]['description']);
    }

    public function test_it_does_not_silently_trim_operator_text_before_planning(): void
    {
        $description = "  first line\r\nsecond line 🚢  ";
        $normalized = (new SourceNormalizer)->normalize(['objects' => [
            'subnets' => [['id' => 'text-prefix', 'subnet' => '192.0.2.0', 'mask' => '24', 'description' => $description]],
            'addresses' => [['id' => 'text-ip', 'subnetId' => 'text-prefix', 'ip_addr' => '192.0.2.1', 'description' => $description]],
        ]]);

        self::assertSame($description, $normalized['objects']['prefixes'][0]['description']);
        self::assertSame($description, $normalized['objects']['ip_addresses'][0]['description']);
    }

    public function test_it_retains_phpipam_tag_references_for_safe_netbox_assignment(): void
    {
        $normalized = (new SourceNormalizer)->normalize(['objects' => [
            'subnets' => [['id' => 'prefix-tagged', 'subnet' => '192.0.2.0', 'mask' => '24', 'tag' => '7']],
            'addresses' => [['id' => 'ip-tagged', 'subnetId' => 'prefix-tagged', 'ip_addr' => '192.0.2.1', 'tag' => '7']],
            'tags' => [['id' => '7', 'type' => 'Production']],
        ]]);

        self::assertSame('7', $normalized['objects']['prefixes'][0]['tag_source_id']);
        self::assertSame('7', $normalized['objects']['ip_addresses'][0]['tag_source_id']);
    }

    public function test_it_canonicalizes_the_full_supported_cidr_edge_matrix_without_losing_special_ipv6_ranges(): void
    {
        $normalized = (new SourceNormalizer)->normalize(['objects' => [
            'subnets' => [
                ['id' => 'v4-default', 'subnet' => '0', 'mask' => '0'],
                ['id' => 'v4-half', 'subnet' => '128.0.0.1', 'mask' => '1'],
                ['id' => 'v4-p2p', 'subnet' => '198.51.100.3', 'mask' => '30'],
                ['id' => 'v6-default', 'subnet' => '2001:0DB8:0000:0000:0000:0000:0000:0001', 'mask' => '64'],
                ['id' => 'v6-p2p', 'subnet' => '2001:db8:1::3', 'mask' => '126'],
                ['id' => 'v6-link-local', 'subnet' => 'FE80::1234', 'mask' => '64'],
                ['id' => 'v6-ula', 'subnet' => 'fd12:3456:789a::abcd', 'mask' => '64'],
                ['id' => 'v6-multicast', 'subnet' => 'FF3E::8000:1', 'mask' => '64'],
            ],
            'addresses' => [
                ['id' => 'ip-v4-default', 'subnetId' => 'v4-default', 'ip_addr' => '0.0.0.0'],
                ['id' => 'ip-v4-p2p', 'subnetId' => 'v4-p2p', 'ip_addr' => '198.51.100.3'],
                ['id' => 'ip-v6-p2p', 'subnetId' => 'v6-p2p', 'ip_addr' => '2001:DB8:1::3'],
                ['id' => 'ip-v6-link-local', 'subnetId' => 'v6-link-local', 'ip_addr' => 'fe80::1234'],
                ['id' => 'ip-v6-ula', 'subnetId' => 'v6-ula', 'ip_addr' => 'FD12:3456:789A::ABCD'],
                ['id' => 'ip-v6-multicast', 'subnetId' => 'v6-multicast', 'ip_addr' => 'ff3e::8000:1'],
            ],
        ]]);

        self::assertSame([
            '0.0.0.0/0',
            '128.0.0.0/1',
            '198.51.100.0/30',
            '2001:db8::/64',
            '2001:db8:1::/126',
            'fe80::/64',
            'fd12:3456:789a::/64',
            'ff3e::/64',
        ], array_column($normalized['objects']['prefixes'], 'prefix'));
        self::assertSame([
            '0.0.0.0/0',
            '198.51.100.3/30',
            '2001:db8:1::3/126',
            'fe80::1234/64',
            'fd12:3456:789a::abcd/64',
            'ff3e::8000:1/64',
        ], array_column($normalized['objects']['ip_addresses'], 'address'));
    }

    public function test_it_normalizes_supported_devices_without_secret_like_fields(): void
    {
        $normalized = (new SourceNormalizer)->normalize(['objects' => [
            'devices' => [[
                'id' => '1',
                'hostname' => 'router.example.test',
                'snmp_community' => 'private-community',
                'snmp_version' => '3',
                'snmp_v3_auth_pass' => 'private-password',
                'api_key' => 'private-key',
                'permissions' => '{"2":2}',
                'username' => 'operator',
                'description' => 'Edge router',
            ]],
        ]]);
        $device = $normalized['objects']['devices'][0];

        self::assertSame('router.example.test', $device['name']);
        self::assertSame('Edge router', $device['description']);
        self::assertArrayNotHasKey('snmp_community', $device['legacy']);
        self::assertArrayNotHasKey('snmp_version', $device['legacy']);
        self::assertArrayNotHasKey('snmp_v3_auth_pass', $device['legacy']);
        self::assertArrayNotHasKey('api_key', $device['legacy']);
        self::assertArrayNotHasKey('permissions', $device['legacy']);
        self::assertArrayNotHasKey('username', $device['legacy']);
        self::assertStringNotContainsString('private-', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    public function test_it_uses_phpipam_device_type_tid_as_the_device_role_identity(): void
    {
        $normalized = (new SourceNormalizer)->normalize(['objects' => [
            'device_types' => [['tid' => '42', 'tname' => 'Router']],
            'devices' => [['id' => '7', 'hostname' => 'edge-01', 'type' => '42']],
        ]]);

        self::assertSame('42', $normalized['objects']['device_roles'][0]['source_id']);
        self::assertSame('42', $normalized['objects']['devices'][0]['category_source_id']);
    }

    public function test_it_decodes_phpipam_decimal_ipv4_addresses_in_their_declared_subnet(): void
    {
        $normalized = (new SourceNormalizer)->normalize(['objects' => [
            'subnets' => [['id' => '120', 'subnet' => '10.120.0.0', 'mask' => '24']],
            'addresses' => [['id' => '120-2', 'subnetId' => '120', 'ip_addr' => '175636482']],
        ]]);

        self::assertSame('10.120.0.2/24', $normalized['objects']['ip_addresses'][0]['address']);
    }

    public function test_it_excludes_sensitive_extension_modules_without_preserving_their_values(): void
    {
        $normalized = (new SourceNormalizer)->normalize(['objects' => [
            'scanAgents' => [['id' => '1', 'name' => 'scanner', 'code' => 'do-not-export-this-value']],
            'vaults' => [['id' => '2', 'name' => 'vault', 'secret' => 'do-not-export-this-secret']],
        ]]);

        self::assertSame([
            'classification' => 'sensitive_excluded',
            'count' => 1,
        ], $normalized['sensitive_excluded']['scanAgents']);
        self::assertSame([
            'classification' => 'sensitive_excluded',
            'count' => 1,
        ], $normalized['sensitive_excluded']['vaults']);
        self::assertStringNotContainsString('do-not-export', json_encode($normalized, JSON_THROW_ON_ERROR));
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

    public function test_it_resolves_nat_address_text_only_when_the_source_ip_is_unambiguous(): void
    {
        $normalized = (new SourceNormalizer)->normalize(['objects' => [
            'subnets' => [
                ['id' => '10', 'subnet' => '167772160', 'mask' => '24', 'vrfId' => 'blue'],
                ['id' => '11', 'subnet' => '3405803776', 'mask' => '24', 'vrfId' => 'blue'],
                ['id' => '12', 'subnet' => '3405803776', 'mask' => '24', 'vrfId' => 'green'],
            ],
            'addresses' => [
                ['id' => 'inside', 'subnetId' => '10', 'ip_addr' => '167772161'],
                ['id' => 'outside-blue', 'subnetId' => '11', 'ip_addr' => '3405803777'],
                ['id' => 'outside-green', 'subnetId' => '12', 'ip_addr' => '3405803777'],
            ],
            'nat' => [
                ['id' => 'nat-ambiguous', 'src' => '10.0.0.1', 'dst' => '203.0.113.1'],
                ['id' => 'nat-unresolved', 'src' => '10.0.0.1', 'dst' => '203.0.113.99'],
            ],
        ]]);

        $ambiguous = collect($normalized['objects']['nat_relations'])->firstWhere('source_id', 'nat-ambiguous');
        self::assertSame('inside', $ambiguous['inside_ip_source_id']);
        self::assertNull($ambiguous['outside_ip_source_id']);
        self::assertTrue($ambiguous['outside_reference_ambiguous']);
        $unresolved = collect($normalized['objects']['nat_relations'])->firstWhere('source_id', 'nat-unresolved');
        self::assertNull($unresolved['outside_ip_source_id']);
    }

    public function test_it_coalesces_repeated_device_ports_without_creating_duplicate_interfaces(): void
    {
        $normalized = (new SourceNormalizer)->normalize(['objects' => [
            'subnets' => [['id' => 'prefix', 'subnet' => '192.0.2.0', 'mask' => '24']],
            'addresses' => [
                ['id' => 'one', 'subnetId' => 'prefix', 'ip_addr' => '192.0.2.1', 'switch' => 'device', 'port' => 'xe-0/0/0'],
                ['id' => 'two', 'subnetId' => 'prefix', 'ip_addr' => '192.0.2.2', 'switch' => 'device', 'port' => 'xe-0/0/0'],
            ],
        ]]);

        self::assertCount(1, $normalized['objects']['interfaces']);
        self::assertSame('device:xe-0/0/0', $normalized['objects']['interfaces'][0]['source_id']);
        self::assertSame(['device:xe-0/0/0', 'device:xe-0/0/0'], array_column($normalized['objects']['ip_addresses'], 'interface_source_id'));
    }

    public function test_it_preserves_a_prefix_when_a_dump_uses_a_structured_location_value(): void
    {
        $normalized = (new SourceNormalizer)->normalize(['objects' => [
            'subnets' => [[
                'id' => '20',
                'subnet' => '167772160',
                'mask' => '24',
                'location' => [],
            ]],
        ]]);

        self::assertSame('10.0.0.0/24', $normalized['objects']['prefixes'][0]['prefix']);
        self::assertNull($normalized['objects']['prefixes'][0]['location_source_id']);
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
