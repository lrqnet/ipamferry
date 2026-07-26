<?php

namespace Tests\Unit;

use App\Domain\Migration\MappingPolicy;
use App\Domain\Migration\MigrationPlanner;
use PHPUnit\Framework\TestCase;

class ExpandedMigrationPlannerTest extends TestCase
{
    public function test_v2_plans_tenancy_dcim_interfaces_and_macs_in_dependency_order(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        foreach (['customer', 'location', 'rack', 'device_role', 'device', 'interface', 'mac_address'] as $type) {
            $mapping['object_policies'][$type] = ['policy' => 'migrate', 'target_type' => $type === 'customer' ? 'tenant' : $type];
        }
        $mapping['relation_rules'] = [
            [
                'id' => 'relation-locations',
                'relation' => 'location_classification',
                'enabled' => true,
                'settings' => [
                    'locations' => [
                        '1' => ['kind' => 'site', 'name' => 'Bogota', 'slug' => 'bogota', 'approved' => true],
                        '2' => ['kind' => 'location', 'name' => 'Room A', 'slug' => 'room-a', 'site_source_id' => '1', 'approved' => true],
                    ],
                ],
            ],
            [
                'id' => 'relation-devices',
                'relation' => 'device_defaults',
                'enabled' => true,
                'settings' => [
                    'categories' => [
                        '10' => [
                            'manufacturer' => ['name' => 'Acme', 'slug' => 'acme', 'approved' => true],
                            'device_type' => ['model' => 'Router 1000', 'slug' => 'router-1000', 'approved' => true],
                            'interface_type' => '1000base-t',
                        ],
                    ],
                ],
            ],
        ];
        $source = ['objects' => [
            'customers' => [$this->source('customer', '50', ['name' => 'Example tenant'])],
            'locations' => [
                $this->source('location', '1', ['name' => 'Bogota']),
                $this->source('location', '2', ['name' => 'Room A']),
            ],
            'racks' => [$this->source('rack', '3', ['name' => 'R1', 'location_source_id' => '2', 'u_height' => 42])],
            'device_roles' => [$this->source('device_role', '10', ['name' => 'Router'])],
            'devices' => [$this->source('device', '4', ['name' => 'edge-01', 'category_source_id' => '10', 'location_source_id' => '2', 'rack_source_id' => '3'])],
            'interfaces' => [$this->source('interface', '4:eth0', ['name' => 'eth0', 'device_source_id' => '4'])],
            'mac_addresses' => [$this->source('mac_address', '4:eth0:AA', ['mac_address' => 'AA:BB:CC:DD:EE:FF', 'interface_source_id' => '4:eth0'])],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        $types = array_column($result['actions'], 'target_type');

        self::assertSame(3, $result['schema_version']);
        self::assertSame([], $result['conflicts']);
        self::assertContains('tenant', $types);
        self::assertContains('site', $types);
        self::assertContains('location', $types);
        self::assertContains('rack', $types);
        self::assertContains('manufacturer', $types);
        self::assertContains('device_type', $types);
        self::assertContains('device_role', $types);
        self::assertContains('device', $types);
        self::assertContains('interface', $types);
        self::assertContains('mac_address', $types);
        $device = collect($result['actions'])->firstWhere('target_type', 'device');
        self::assertArrayHasKey('site_id', $device['natural_key']);
        self::assertLessThan(array_search('device', $types, true), array_search('site', $types, true));
        self::assertLessThan(array_search('interface', $types, true), array_search('device', $types, true));
    }

    public function test_nat_requires_confirmation_rejects_pat_and_emits_idempotent_relation(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['relation_rules'] = [[
            'id' => 'relation-nat',
            'relation' => 'nat_1to1',
            'enabled' => true,
            'settings' => ['confirmed' => true, 'relation_ids' => ['nat-1', 'nat-pat']],
        ]];
        $source = ['objects' => [
            'ip_addresses' => [
                $this->source('ip_address', 'inside', ['address' => '10.0.0.1/32']),
                $this->source('ip_address', 'outside', ['address' => '203.0.113.1/32']),
            ],
            'nat_relations' => [
                $this->source('nat', 'nat-1', ['inside_ip_source_id' => 'inside', 'outside_ip_source_id' => 'outside', 'has_ports' => false]),
                $this->source('nat', 'nat-pat', ['inside_ip_source_id' => 'inside', 'outside_ip_source_id' => 'outside', 'has_ports' => true]),
            ],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        $relations = array_values(array_filter($result['actions'], fn (array $action): bool => $action['operation'] === 'relation'));

        self::assertCount(1, $relations);
        self::assertSame('nat_1to1', $relations[0]['relation']);
        self::assertArrayHasKey('nat_inside', $relations[0]['payload']);
        self::assertStringContainsString('pat_preserved', implode("\n", $result['warnings']));
    }

    public function test_individual_device_exception_can_override_category_hardware_and_interface_type(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        foreach (['location', 'device_role', 'device', 'interface'] as $type) {
            $mapping['object_policies'][$type] = ['policy' => 'migrate', 'target_type' => $type];
        }
        $mapping['relation_rules'] = [
            [
                'id' => 'relation-locations',
                'relation' => 'location_classification',
                'enabled' => true,
                'settings' => [
                    'locations' => [
                        'site-1' => ['kind' => 'site', 'name' => 'Bogota', 'slug' => 'bogota', 'approved' => true],
                    ],
                ],
            ],
            [
                'id' => 'relation-devices',
                'relation' => 'device_defaults',
                'enabled' => true,
                'settings' => [
                    'categories' => [
                        'role-1' => [
                            'manufacturer' => ['name' => 'Base', 'slug' => 'base', 'approved' => true],
                            'device_type' => ['model' => 'Base Router', 'slug' => 'base-router', 'approved' => true],
                            'interface_type' => '1000base-t',
                        ],
                    ],
                    'devices' => [
                        'device-1' => [
                            'role_source_id' => 'role-1',
                            'manufacturer' => ['name' => 'Exception', 'slug' => 'exception', 'approved' => true],
                            'device_type' => ['model' => 'Exception Router', 'slug' => 'exception-router', 'approved' => true],
                            'interface_type' => '10gbase-x-sfpp',
                        ],
                    ],
                ],
            ],
        ];
        $source = ['objects' => [
            'locations' => [$this->source('location', 'site-1', ['name' => 'Bogota'])],
            'device_roles' => [$this->source('device_role', 'role-1', ['name' => 'Router'])],
            'devices' => [$this->source('device', 'device-1', [
                'name' => 'edge-01',
                'category_source_id' => 'role-1',
                'location_source_id' => 'site-1',
            ])],
            'interfaces' => [$this->source('interface', 'device-1:eth0', [
                'name' => 'eth0',
                'device_source_id' => 'device-1',
            ])],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        $device = collect($result['actions'])->firstWhere('target_type', 'device');
        $interface = collect($result['actions'])->firstWhere('target_type', 'interface');
        $exceptionType = collect($result['actions'])->first(
            fn (array $action): bool => $action['target_type'] === 'device_type'
                && $action['source_id'] === 'device:device-1',
        );

        self::assertSame([], $result['conflicts']);
        self::assertNotNull($exceptionType);
        self::assertSame('Exception Router', $exceptionType['payload']['model']);
        self::assertSame('10gbase-x-sfpp', $interface['payload']['type']);
        self::assertSame(['site_id', 'name'], array_keys($device['natural_key']));
    }

    public function test_unapproved_auxiliary_objects_block_the_plan(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['object_policies']['location'] = ['policy' => 'migrate', 'target_type' => 'site'];
        $mapping['relation_rules'] = [[
            'id' => 'relation-locations',
            'relation' => 'location_classification',
            'enabled' => true,
            'settings' => ['locations' => ['1' => ['kind' => 'site', 'name' => 'HQ', 'slug' => 'hq', 'approved' => false]]],
        ]];
        $source = ['objects' => ['locations' => [$this->source('location', '1', ['name' => 'HQ'])]]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);

        self::assertContains('auxiliary_creation_unapproved', array_column($result['conflicts'], 'reason'));
        self::assertSame([], $result['actions']);
    }

    public function test_v2_field_transformations_update_regular_fields_without_executable_code(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['field_rules'] = [
            [
                'id' => 'field-normalize',
                'source_type' => 'prefix',
                'source_field' => 'owner',
                'target' => 'description',
                'target_kind' => 'field',
                'action' => 'normalize',
                'mode' => 'upper',
            ],
            [
                'id' => 'field-lookup',
                'source_type' => 'prefix',
                'source_field' => 'environment',
                'target' => 'phpipam_environment',
                'target_kind' => 'custom_field',
                'action' => 'lookup',
                'table' => ['p' => 'production'],
                'data_type' => 'text',
            ],
        ];
        $source = [
            'custom_fields' => [],
            'objects' => [
                'prefixes' => [$this->source('prefix', '1', [
                    'prefix' => '10.0.0.0/24',
                    'legacy' => ['owner' => ' network team ', 'environment' => 'p'],
                ])],
            ],
        ];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        $prefix = collect($result['actions'])->firstWhere('target_type', 'prefix');
        $customField = collect($result['actions'])->firstWhere('target_type', 'custom_field');

        self::assertSame('NETWORK TEAM', $prefix['payload']['description']);
        self::assertSame('production', $prefix['payload']['custom_fields']['phpipam_environment']);
        self::assertSame('phpipam_environment', $customField['natural_key']['name']);
    }

    public function test_sections_and_ip_tags_with_the_same_numeric_id_keep_distinct_source_links(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['object_policies']['section'] = ['policy' => 'migrate', 'target_type' => 'tag'];
        $mapping['object_policies']['tag'] = ['policy' => 'migrate', 'target_type' => 'tag'];
        $source = ['objects' => [
            'sections' => [$this->source('section', '7', ['name' => 'Campus'])],
            'tags' => [$this->source('tag', '7', ['name' => 'Production'])],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        $tags = array_values(array_filter(
            $result['actions'],
            fn (array $action): bool => $action['target_type'] === 'tag',
        ));

        self::assertCount(2, $tags);
        self::assertSame(['section:7', 'tag:7'], array_column($tags, 'source_id'));
        self::assertSame(['campus', 'production'], array_map(
            fn (array $action): string => $action['natural_key']['slug'],
            $tags,
        ));
    }

    public function test_v2_plans_contacts_circuit_terminations_and_asns_only_after_approved_dependencies(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        foreach ([
            'customer' => 'tenant',
            'location' => 'site',
            'provider' => 'provider',
            'circuit_type' => 'circuit_type',
            'circuit' => 'circuit',
            'asn' => 'asn',
        ] as $sourceType => $targetType) {
            $mapping['object_policies'][$sourceType] = ['policy' => 'migrate', 'target_type' => $targetType];
        }
        $mapping['relation_rules'] = [
            [
                'id' => 'relation-contacts',
                'relation' => 'customer_contacts',
                'enabled' => true,
                'settings' => [
                    'contact_role' => ['id' => 'customer', 'name' => 'Customer', 'slug' => 'customer', 'approved' => true],
                ],
            ],
            [
                'id' => 'relation-locations',
                'relation' => 'location_classification',
                'enabled' => true,
                'settings' => [
                    'locations' => [
                        'site-1' => ['kind' => 'site', 'name' => 'Bogota', 'slug' => 'bogota', 'approved' => true],
                    ],
                ],
            ],
            [
                'id' => 'relation-circuits',
                'relation' => 'circuit_terminations',
                'enabled' => true,
                'settings' => ['circuit_ids' => ['circuit-1']],
            ],
            [
                'id' => 'relation-asn',
                'relation' => 'asn_defaults',
                'enabled' => true,
                'settings' => [
                    'rir' => ['id' => 'private', 'name' => 'Private', 'slug' => 'private', 'is_private' => true, 'approved' => true],
                ],
            ],
        ];
        $source = ['objects' => [
            'customers' => [$this->source('customer', 'tenant-1', [
                'name' => 'Example',
                'contact_name' => 'Network Team',
                'contact_email' => 'network@example.test',
            ])],
            'locations' => [$this->source('location', 'site-1', ['name' => 'Bogota'])],
            'providers' => [$this->source('provider', 'provider-1', ['name' => 'Carrier'])],
            'circuit_types' => [$this->source('circuit_type', 'type-1', ['name' => 'Transit'])],
            'circuits' => [$this->source('circuit', 'circuit-1', [
                'cid' => 'CID-100',
                'provider_source_id' => 'provider-1',
                'type_source_id' => 'type-1',
                'location_a_source_id' => 'site-1',
                'location_z_source_id' => 'site-1',
            ])],
            'asns' => [$this->source('asn', '65001', ['asn' => 65001, 'description' => 'Private ASN'])],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        $types = array_column($result['actions'], 'target_type');

        self::assertSame([], $result['conflicts']);
        self::assertContains('tenant', $types);
        self::assertContains('contact_role', $types);
        self::assertContains('contact', $types);
        self::assertContains('contact_assignment', $types);
        self::assertContains('circuit', $types);
        self::assertSame(2, count(array_filter($types, fn (string $type): bool => $type === 'circuit_termination')));
        self::assertContains('rir', $types);
        self::assertContains('asn', $types);
        self::assertLessThan(array_search('contact_assignment', $types, true), array_search('tenant', $types, true));
        self::assertLessThan(array_search('circuit', $types, true), array_search('provider', $types, true));
        self::assertLessThan(array_search('asn', $types, true), array_search('rir', $types, true));
    }

    private function source(string $type, string $id, array $values): array
    {
        return [
            'source_type' => $type,
            'source_id' => $id,
            'source_hash' => hash('sha256', "{$type}:{$id}"),
            'legacy' => [],
            ...$values,
        ];
    }
}
