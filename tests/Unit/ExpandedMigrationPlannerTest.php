<?php

namespace Tests\Unit;

use App\Domain\Migration\MappingPolicy;
use App\Domain\Migration\MigrationPlanner;
use App\Domain\Migration\Planners\RelationsPlanner;
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

    public function test_duplicate_mac_across_interfaces_is_a_blocking_conflict_before_apply(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        foreach (['location', 'device_role', 'device', 'interface', 'mac_address'] as $type) {
            $mapping['object_policies'][$type] = ['policy' => 'migrate', 'target_type' => $type];
        }
        $mapping['relation_rules'] = [
            ['id' => 'location', 'relation' => 'location_classification', 'enabled' => true, 'settings' => [
                'locations' => ['site' => ['kind' => 'site', 'name' => 'Lab', 'slug' => 'lab', 'approved' => true]],
            ]],
            ['id' => 'device', 'relation' => 'device_defaults', 'enabled' => true, 'settings' => [
                'categories' => ['role' => [
                    'manufacturer' => ['name' => 'Acme', 'slug' => 'acme', 'approved' => true],
                    'device_type' => ['model' => 'Router', 'slug' => 'router', 'approved' => true],
                    'interface_type' => '1000base-t',
                ]],
            ]],
        ];
        $source = ['objects' => [
            'locations' => [$this->source('location', 'site', ['name' => 'Lab'])],
            'device_roles' => [$this->source('device_role', 'role', ['name' => 'Router'])],
            'devices' => [$this->source('device', 'device', ['name' => 'edge-1', 'category_source_id' => 'role', 'location_source_id' => 'site'])],
            'interfaces' => [
                $this->source('interface', 'device:eth0', ['name' => 'eth0', 'device_source_id' => 'device']),
                $this->source('interface', 'device:eth1', ['name' => 'eth1', 'device_source_id' => 'device']),
            ],
            'mac_addresses' => [
                $this->source('mac_address', 'first', ['mac_address' => 'AA:BB:CC:DD:EE:FF', 'interface_source_id' => 'device:eth0']),
                $this->source('mac_address', 'second', ['mac_address' => 'AA:BB:CC:DD:EE:FF', 'interface_source_id' => 'device:eth1']),
            ],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);

        self::assertContains('duplicate_target_claim', array_column($result['conflicts'], 'reason'));
        self::assertCount(2, array_filter($result['actions'], fn (array $action): bool => ($action['target_type'] ?? null) === 'mac_address'));
    }

    public function test_device_without_a_source_location_uses_only_an_explicit_approved_fallback_site(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        foreach (['device_role', 'device'] as $type) {
            $mapping['object_policies'][$type] = ['policy' => 'migrate', 'target_type' => $type];
        }
        $mapping['relation_rules'] = [
            ['id' => 'fallback', 'relation' => 'location_classification', 'enabled' => true, 'settings' => [
                'fallback_site' => ['id' => 'fallback', 'name' => 'Fallback', 'slug' => 'fallback', 'approved' => true],
            ]],
            ['id' => 'device', 'relation' => 'device_defaults', 'enabled' => true, 'settings' => [
                'categories' => ['role' => [
                    'manufacturer' => ['name' => 'Acme', 'slug' => 'acme', 'approved' => true],
                    'device_type' => ['model' => 'Router', 'slug' => 'router', 'approved' => true],
                ]],
            ]],
        ];
        $source = ['objects' => [
            'device_roles' => [$this->source('device_role', 'role', ['name' => 'Router'])],
            'devices' => [$this->source('device', 'device', ['name' => 'edge-1', 'category_source_id' => 'role'])],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        $device = collect($result['actions'])->firstWhere('target_type', 'device');
        $fallbackSite = collect($result['actions'])->firstWhere('target_type', 'site');

        self::assertSame([], $result['conflicts']);
        self::assertArrayHasKey('$ref', $device['natural_key']['site_id']);
        self::assertSame($fallbackSite['action_key'], $device['natural_key']['site_id']['$ref']);
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

    public function test_nat_crossing_vrfs_is_preserved_even_when_the_operator_confirms_it(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['relation_rules'] = [[
            'id' => 'relation-nat-cross-vrf',
            'relation' => 'nat_1to1',
            'enabled' => true,
            'settings' => ['confirmed' => true, 'relation_ids' => ['nat-cross-vrf']],
        ]];
        $source = ['objects' => [
            'ip_addresses' => [
                $this->source('ip_address', 'inside', ['address' => '10.0.0.1/32']),
                $this->source('ip_address', 'outside', ['address' => '203.0.113.1/32']),
            ],
            'nat_relations' => [$this->source('nat', 'nat-cross-vrf', [
                'inside_ip_source_id' => 'inside',
                'outside_ip_source_id' => 'outside',
                'inside_vrf_source_id' => 'blue',
                'outside_vrf_source_id' => 'green',
                'has_ports' => false,
            ])],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);

        self::assertSame([], array_values(array_filter($result['actions'], fn (array $action): bool => $action['operation'] === 'relation')));
        self::assertStringContainsString('nat_cross_vrf_preserved', implode("\n", $result['warnings']));
    }

    public function test_nat_with_a_shared_inside_or_outside_address_is_preserved_as_non_one_to_one(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['relation_rules'] = [[
            'id' => 'relation-nat-many',
            'relation' => 'nat_1to1',
            'enabled' => true,
            'settings' => ['confirmed' => true, 'relation_ids' => ['nat-a', 'nat-b']],
        ]];
        $source = ['objects' => [
            'ip_addresses' => [
                $this->source('ip_address', 'inside', ['address' => '10.0.0.1/32']),
                $this->source('ip_address', 'outside-a', ['address' => '203.0.113.1/32']),
                $this->source('ip_address', 'outside-b', ['address' => '203.0.113.2/32']),
            ],
            'nat_relations' => [
                $this->source('nat', 'nat-a', ['inside_ip_source_id' => 'inside', 'outside_ip_source_id' => 'outside-a', 'has_ports' => false]),
                $this->source('nat', 'nat-b', ['inside_ip_source_id' => 'inside', 'outside_ip_source_id' => 'outside-b', 'has_ports' => false]),
            ],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);

        self::assertSame([], array_values(array_filter($result['actions'], fn (array $action): bool => ($action['operation'] ?? null) === 'relation')));
        self::assertSame(2, substr_count(implode("\n", $result['warnings']), 'nat_many_to_many_preserved'));
    }

    public function test_confirmed_nat_with_a_missing_endpoint_is_preserved_as_incomplete_not_cross_vrf(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['relation_rules'] = [[
            'id' => 'relation-nat-incomplete',
            'relation' => 'nat_1to1',
            'enabled' => true,
            'settings' => ['confirmed' => true, 'relation_ids' => ['incomplete']],
        ]];
        $source = ['objects' => [
            'nat_relations' => [$this->source('nat', 'incomplete', [
                'inside_ip_source_id' => 'inside',
                'outside_ip_source_id' => null,
                'inside_vrf_source_id' => 'blue',
                'outside_vrf_source_id' => null,
                'has_ports' => false,
            ])],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);

        self::assertStringContainsString('nat_ip_pair_required', implode("\n", $result['warnings']));
        self::assertStringNotContainsString('nat_cross_vrf_preserved', implode("\n", $result['warnings']));
    }

    public function test_an_explicit_ip_assignment_update_keeps_type_and_source_reference_together(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['object_policies']['interface'] = ['policy' => 'migrate', 'target_type' => 'interface'];
        $mapping['update_rules']['ip_address'] = ['assigned_object_type', 'assigned_object_id'];
        $source = ['objects' => [
            'interfaces' => [$this->source('interface', 'device-1:eth0', ['name' => 'eth0', 'device_source_id' => 'device-1'])],
            'ip_addresses' => [$this->source('ip_address', 'ip-1', [
                'address' => '192.0.2.1/24',
                'interface_source_id' => 'device-1:eth0',
            ])],
        ]];
        $target = ['objects' => [
            'ip_addresses' => [['id' => 10, 'address' => '192.0.2.1/24']],
        ]];

        $result = (new MigrationPlanner)->plan($source, $target, $mapping);
        $address = collect($result['actions'])->firstWhere('source_id', 'ip-1');

        self::assertSame('update', $address['operation']);
        self::assertSame('dcim.interface', $address['payload']['assigned_object_type']);
        self::assertArrayHasKey('assigned_object_id', $address['payload']);
    }

    public function test_a_relation_waits_for_an_update_to_an_existing_ip_address(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['update_rules']['ip_address'] = ['description'];
        $mapping['relation_rules'] = [[
            'id' => 'relation-nat',
            'relation' => 'nat_1to1',
            'enabled' => true,
            'settings' => ['confirmed' => true, 'relation_ids' => ['nat-1']],
        ]];
        $source = ['objects' => [
            'ip_addresses' => [
                $this->source('ip_address', 'inside', ['address' => '10.0.0.1/32', 'description' => 'updated inside']),
                $this->source('ip_address', 'outside', ['address' => '203.0.113.1/32', 'description' => 'updated outside']),
            ],
            'nat_relations' => [
                $this->source('nat', 'nat-1', [
                    'inside_ip_source_id' => 'inside',
                    'outside_ip_source_id' => 'outside',
                    'inside_vrf_source_id' => null,
                    'outside_vrf_source_id' => null,
                    'has_ports' => false,
                ]),
            ],
        ]];
        $target = ['objects' => [
            'ip_addresses' => [
                ['id' => 10, 'address' => '10.0.0.1/32', 'description' => 'old'],
                ['id' => 11, 'address' => '203.0.113.1/32', 'description' => 'old'],
            ],
        ]];

        $result = (new MigrationPlanner)->plan($source, $target, $mapping);
        $inside = collect($result['actions'])->firstWhere('source_id', 'inside');
        $outside = collect($result['actions'])->firstWhere('source_id', 'outside');
        $relation = collect($result['actions'])->first(fn (array $action): bool => ($action['relation'] ?? null) === 'nat_1to1');

        self::assertSame('update', $inside['operation']);
        self::assertSame('update', $outside['operation']);
        self::assertSame(['$ref' => $inside['action_key']], $relation['payload']['nat_inside']);
        self::assertContains($outside['action_key'], $relation['dependencies']);
        self::assertContains($inside['action_key'], $relation['dependencies']);
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

    public function test_invalid_customer_contact_is_preserved_while_the_tenant_remains_migratable(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['object_policies']['customer'] = ['policy' => 'migrate', 'target_type' => 'tenant'];
        $mapping['relation_rules'] = [[
            'id' => 'customer-contacts',
            'relation' => 'customer_contacts',
            'enabled' => true,
            'settings' => ['contact_role' => [
                'id' => 'customer', 'name' => 'Customer', 'slug' => 'customer', 'approved' => true,
            ]],
        ]];
        $source = ['objects' => ['customers' => [$this->source('customer', '1', [
            'name' => 'Tenant with invalid contact',
            'contact_name' => 'Invalid contact',
            'contact_email' => 'invalid contact@example.test',
            'address' => "Street 1\nBogota",
        ])]]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);

        self::assertSame([], $result['conflicts']);
        self::assertStringContainsString('customer_contact_invalid_preserved', implode("\n", $result['warnings']));
        self::assertContains('tenant', array_column($result['actions'], 'target_type'));
        self::assertNotContains('contact', array_column($result['actions'], 'target_type'));
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

    public function test_v2_can_map_a_phpipam_note_to_netbox_comments_without_truncation(): void
    {
        $comment = "Operator note\n".str_repeat('A', 512);
        $mapping = MappingPolicy::v2Defaults();
        $mapping['field_rules'] = [[
            'id' => 'field-note-to-comments',
            'source_type' => 'ip_address',
            'source_field' => 'note',
            'target' => 'comments',
            'target_kind' => 'field',
            'action' => 'copy',
        ]];
        $source = ['objects' => [
            'ip_addresses' => [$this->source('ip_address', '1', [
                'address' => '2001:db8::1/128',
                'legacy' => ['note' => $comment],
            ])],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        $address = collect($result['actions'])->firstWhere('target_type', 'ip_address');

        self::assertSame($comment, $address['payload']['comments']);
    }

    public function test_v2_preserves_unapproved_extended_objects_without_blocking_the_core_ipam_plan(): void
    {
        $source = ['objects' => [
            'customers' => [$this->source('customer', '1', ['name' => 'Preserved tenant'])],
            'locations' => [$this->source('location', '1', ['name' => 'Preserved location'])],
            'devices' => [$this->source('device', '1', ['name' => 'Preserved device'])],
            'prefixes' => [$this->source('prefix', '1', [
                'prefix' => '10.120.0.0/24',
                'tenant_source_id' => '1',
            ])],
            'ip_addresses' => [$this->source('ip_address', '1', [
                'address' => '10.120.0.1/24',
                'prefix_source_id' => '1',
                'tenant_source_id' => '1',
                'interface_source_id' => '1:eth0',
            ])],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], MappingPolicy::v2Defaults());

        self::assertSame([], $result['conflicts']);
        self::assertCount(2, $result['actions']);
        $address = collect($result['actions'])->firstWhere('target_type', 'ip_address');
        self::assertArrayNotHasKey('tenant', $address['payload']);
        self::assertArrayNotHasKey('assigned_object_id', $address['payload']);
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

    public function test_duplicate_circuit_cids_are_blocked_before_any_netbox_request(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        foreach (['provider', 'circuit_type', 'circuit'] as $type) {
            $mapping['object_policies'][$type] = ['policy' => 'migrate', 'target_type' => $type];
        }
        $source = ['objects' => [
            'providers' => [$this->source('provider', 'carrier', ['name' => 'Carrier'])],
            'circuit_types' => [$this->source('circuit_type', 'transit', ['name' => 'Transit'])],
            'circuits' => [
                $this->source('circuit', 'first', ['cid' => 'DUPLICATE-CID', 'provider_source_id' => 'carrier', 'type_source_id' => 'transit']),
                $this->source('circuit', 'second', ['cid' => 'DUPLICATE-CID', 'provider_source_id' => 'carrier', 'type_source_id' => 'transit']),
            ],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);

        self::assertContains('duplicate_target_claim', array_column($result['conflicts'], 'reason'));
        self::assertCount(2, array_filter($result['actions'], fn (array $action): bool => ($action['target_type'] ?? null) === 'circuit'));
    }

    public function test_select_custom_fields_create_an_approved_choice_set_before_the_field(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['field_rules'] = [[
            'id' => 'field-environment',
            'source_type' => 'prefix',
            'source_field' => 'environment',
            'target' => 'environment',
            'target_kind' => 'custom_field',
            'action' => 'copy',
            'data_type' => 'select',
            'choice_set' => [
                'name' => 'IpamFerry environment',
                'choices' => ['production', 'staging'],
                'approved' => true,
            ],
        ]];
        $source = ['objects' => ['prefixes' => [$this->source('prefix', 'prefix-1', [
            'prefix' => '192.0.2.0/24',
            'legacy' => ['environment' => 'production'],
        ])]]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        $choiceSet = collect($result['actions'])->firstWhere('target_type', 'custom_field_choice_set');
        $customField = collect($result['actions'])->firstWhere('target_type', 'custom_field');
        $prefix = collect($result['actions'])->firstWhere('target_type', 'prefix');

        self::assertSame([], $result['conflicts']);
        self::assertSame([['production', 'production'], ['staging', 'staging']], $choiceSet['payload']['extra_choices']);
        self::assertArrayHasKey('$ref', $customField['payload']['choice_set']);
        self::assertContains($choiceSet['action_key'], $customField['dependencies']);
        self::assertContains($customField['action_key'], $prefix['dependencies']);

        $mapping['field_rules'][0]['choice_set']['approved'] = false;
        $blocked = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        self::assertContains('auxiliary_creation_unapproved', array_column($blocked['conflicts'], 'reason'));
    }

    public function test_zero_length_networks_are_preserved_before_any_netbox_write(): void
    {
        $source = ['objects' => [
            'prefixes' => [$this->source('prefix', 'default-route', ['prefix' => '0.0.0.0/0'])],
            'ip_addresses' => [$this->source('ip_address', 'default-address', ['address' => '0.0.0.0/0'])],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], MappingPolicy::v2Defaults());

        self::assertSame([], $result['conflicts']);
        self::assertSame([], $result['actions']);
        self::assertStringContainsString('netbox_prefix_zero_length_preserved', implode("\n", $result['warnings']));
        self::assertStringContainsString('netbox_ip_address_zero_length_preserved', implode("\n", $result['warnings']));
        self::assertStringContainsString('0.0.0.0/0', implode("\n", $result['warnings']));
    }

    public function test_legacy_mapping_also_preserves_zero_length_networks(): void
    {
        $source = ['objects' => [
            'prefixes' => [$this->source('prefix', 'default-route', ['prefix' => '0.0.0.0/0'])],
            'ip_addresses' => [$this->source('ip_address', 'default-address', ['address' => '0.0.0.0/0'])],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], MappingPolicy::defaults());
        $actions = collect($result['actions'])->keyBy(fn (array $action): string => "{$action['source_type']}:{$action['source_id']}");

        self::assertSame([], $result['conflicts']);
        self::assertSame('ignore', $actions['prefix:default-route']['operation']);
        self::assertSame('ignore', $actions['ip_address:default-address']['operation']);
        self::assertStringContainsString('netbox_prefix_zero_length_preserved', implode("\n", $result['warnings']));
        self::assertStringContainsString('netbox_ip_address_zero_length_preserved', implode("\n", $result['warnings']));
    }

    public function test_vlan_ids_outside_the_netbox_range_are_preserved_without_an_api_action(): void
    {
        $source = ['objects' => ['vlans' => [
            $this->source('vlan', 'zero', ['vid' => 0, 'name' => 'Reserved VLAN zero']),
            $this->source('vlan', 'too-high', ['vid' => 4095, 'name' => 'Reserved VLAN 4095']),
            $this->source('vlan', 'not-a-number', ['vid' => 'invalid', 'name' => 'Invalid VLAN']),
            $this->source('vlan', 'valid', ['vid' => 4094, 'name' => 'Valid VLAN']),
        ]]];

        $v2 = (new MigrationPlanner)->plan($source, ['objects' => []], MappingPolicy::v2Defaults());
        $v2Actions = collect($v2['actions'])->keyBy(fn (array $action): string => "{$action['source_type']}:{$action['source_id']}");
        self::assertArrayNotHasKey('vlan:zero', $v2Actions);
        self::assertArrayNotHasKey('vlan:too-high', $v2Actions);
        self::assertArrayNotHasKey('vlan:not-a-number', $v2Actions);
        self::assertSame(4094, $v2Actions['vlan:valid']['payload']['vid']);
        self::assertStringContainsString('vlan_vid_out_of_range_preserved', implode("\n", $v2['warnings']));
        self::assertStringContainsString('vlan_vid_invalid_preserved', implode("\n", $v2['warnings']));

        $legacy = (new MigrationPlanner)->plan($source, ['objects' => []], MappingPolicy::defaults());
        $legacyActions = collect($legacy['actions'])->keyBy(fn (array $action): string => "{$action['source_type']}:{$action['source_id']}");
        self::assertSame('ignore', $legacyActions['vlan:zero']['operation']);
        self::assertSame('ignore', $legacyActions['vlan:too-high']['operation']);
        self::assertSame('ignore', $legacyActions['vlan:not-a-number']['operation']);
        self::assertSame('create', $legacyActions['vlan:valid']['operation']);
    }

    public function test_vrfs_keep_parallel_prefixes_and_ungrouped_unicode_vlans_have_safe_natural_keys(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['object_policies']['customer'] = ['policy' => 'migrate', 'target_type' => 'tenant'];
        $source = ['objects' => [
            'customers' => [$this->source('customer', 'tenant-1', ['name' => 'Example Tenant'])],
            'vrfs' => [
                $this->source('vrf', 'blue', ['name' => 'Blue', 'rd' => '65000:100', 'tenant_source_id' => 'tenant-1']),
                $this->source('vrf', 'green', ['name' => 'Green', 'rd' => '65000:200', 'tenant_source_id' => 'tenant-1']),
            ],
            'vlans' => [$this->source('vlan', 'ungrouped', ['vid' => 4094, 'name' => 'VLAN Ñandú 🚢'])],
            'prefixes' => [
                $this->source('prefix', 'blue-prefix', ['prefix' => '198.51.100.0/24', 'vrf_source_id' => 'blue']),
                $this->source('prefix', 'green-prefix', ['prefix' => '198.51.100.0/24', 'vrf_source_id' => 'green']),
            ],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        $actions = collect($result['actions'])->keyBy(fn (array $action): string => "{$action['source_type']}:{$action['source_id']}");

        self::assertSame([], $result['conflicts']);
        self::assertArrayHasKey('$ref', $actions['vrf:blue']['payload']['tenant']);
        self::assertSame(4094, $actions['vlan:ungrouped']['natural_key']['vid']);
        self::assertNull($actions['vlan:ungrouped']['natural_key']['group_id']);
        self::assertSame('VLAN Ñandú 🚢', $actions['vlan:ungrouped']['payload']['name']);
        self::assertNotSame(
            $actions['prefix:blue-prefix']['natural_key']['vrf_id']['$ref'],
            $actions['prefix:green-prefix']['natural_key']['vrf_id']['$ref'],
        );
    }

    public function test_an_invalid_ip_dns_name_is_preserved_without_blocking_the_valid_ip_address(): void
    {
        $source = ['objects' => ['ip_addresses' => [
            $this->source('ip_address', 'invalid-hostname', [
                'address' => '192.0.2.10/24',
                'dns_name' => 'invalid host_name.example.test',
            ]),
        ]]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], MappingPolicy::v2Defaults());
        $ip = collect($result['actions'])->firstWhere('target_type', 'ip_address');

        self::assertSame([], $result['conflicts']);
        self::assertSame('', $ip['payload']['dns_name']);
        self::assertStringContainsString('ip_dns_name_invalid_preserved', implode("\n", $result['warnings']));
    }

    public function test_duplicate_rds_and_duplicate_blank_rd_names_block_planning_before_apply(): void
    {
        $source = ['objects' => ['vrfs' => [
            $this->source('vrf', 'first-rd', ['name' => 'First', 'rd' => '65000:100']),
            $this->source('vrf', 'duplicate-rd', ['name' => 'Second', 'rd' => '65000:100']),
            $this->source('vrf', 'blank-rd-first', ['name' => 'Shared name', 'rd' => null]),
            $this->source('vrf', 'blank-rd-second', ['name' => 'shared NAME', 'rd' => '']),
        ]]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], MappingPolicy::v2Defaults());

        self::assertContains('duplicate_vrf_rd', array_column($result['conflicts'], 'reason'));
        self::assertContains('duplicate_vrf_name_without_rd', array_column($result['conflicts'], 'reason'));
        self::assertCount(2, array_filter($result['actions'], fn (array $action): bool => $action['target_type'] === 'vrf'));
    }

    public function test_prefix_and_ip_tags_are_created_before_their_safe_assignments(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['object_policies']['tag'] = ['policy' => 'migrate', 'target_type' => 'tag'];
        $source = ['objects' => [
            'tags' => [$this->source('tag', '7', ['name' => 'Production', 'slug' => 'production', 'color' => '00aa00'])],
            'prefixes' => [$this->source('prefix', 'prefix', ['prefix' => '192.0.2.0/24', 'tag_source_id' => '7'])],
            'ip_addresses' => [$this->source('ip_address', 'ip', ['address' => '192.0.2.1/24', 'tag_source_id' => '7'])],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        $actions = collect($result['actions'])->keyBy(fn (array $action): string => "{$action['source_type']}:{$action['source_id']}");

        self::assertSame([], $result['conflicts']);
        self::assertArrayHasKey('$ref', $actions['prefix:prefix']['payload']['tags'][0]);
        self::assertArrayHasKey('$ref', $actions['ip_address:ip']['payload']['tags'][0]);
        self::assertContains($actions['tag:tag:7']['action_key'], $actions['prefix:prefix']['dependencies']);
        self::assertContains($actions['tag:tag:7']['action_key'], $actions['ip_address:ip']['dependencies']);
    }

    public function test_primary_ipv6_is_planned_only_for_an_unambiguous_address_assigned_to_the_device_interface(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['object_policies']['device'] = ['policy' => 'migrate', 'target_type' => 'device'];
        $mapping['object_policies']['interface'] = ['policy' => 'migrate', 'target_type' => 'interface'];
        $mapping['relation_rules'] = [[
            'id' => 'primary-ip',
            'relation' => 'primary_ip',
            'enabled' => true,
            'settings' => [],
        ]];
        $source = ['objects' => [
            'devices' => [$this->source('device', 'router-6', [
                'name' => 'router-6',
                'primary_ip_source' => '2001:db8:120::2',
            ])],
            'interfaces' => [$this->source('interface', 'router-6:xe-0/0/0', [
                'name' => 'xe-0/0/0',
                'device_source_id' => 'router-6',
            ])],
            'ip_addresses' => [
                $this->source('ip_address', 'router-6-ipv6', [
                    'address' => '2001:db8:120::2/64',
                    'device_source_id' => 'router-6',
                    'interface_source_id' => 'router-6:xe-0/0/0',
                ]),
                // The same literal address on an unassigned source row must
                // not make the device primary relation ambiguous.
                $this->source('ip_address', 'unassigned-copy', [
                    'address' => '2001:db8:120::2/64',
                ]),
            ],
        ]];

        $relations = (new RelationsPlanner)->relations($source['objects'], new MappingPolicy($mapping));

        self::assertCount(1, $relations);
        self::assertSame('primary_ip', $relations[0]['relation']);
        self::assertSame(['$source_ref' => ['target_type' => 'ip_address', 'source_id' => 'router-6-ipv6']], $relations[0]['payload']['primary_ip6']);
        self::assertArrayNotHasKey('primary_ip4', $relations[0]['payload']);
    }

    public function test_generic_hardware_requires_explicit_confirmation_before_any_dcim_hardware_is_planned(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        foreach (['location', 'device_role', 'device'] as $type) {
            $mapping['object_policies'][$type] = ['policy' => 'migrate', 'target_type' => $type];
        }
        $mapping['relation_rules'] = [
            ['id' => 'location', 'relation' => 'location_classification', 'enabled' => true, 'settings' => [
                'locations' => ['site' => ['kind' => 'site', 'name' => 'Lab', 'slug' => 'lab', 'approved' => true]],
            ]],
            ['id' => 'device', 'relation' => 'device_defaults', 'enabled' => true, 'settings' => [
                'categories' => ['router' => [
                    'manufacturer' => ['name' => 'Generic', 'slug' => 'generic', 'approved' => true],
                    'device_type' => ['model' => 'Generic Router', 'slug' => 'generic-router', 'approved' => true],
                    'interface_type' => '1000base-t',
                ]],
            ]],
        ];
        $source = ['objects' => [
            'locations' => [$this->source('location', 'site', ['name' => 'Lab'])],
            'device_roles' => [$this->source('device_role', 'router', ['name' => 'Router'])],
            'devices' => [$this->source('device', 'edge-01', ['name' => 'edge-01', 'category_source_id' => 'router', 'location_source_id' => 'site'])],
        ]];

        $blocked = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        self::assertContains('device_prerequisites_required', array_column($blocked['conflicts'], 'reason'));
        self::assertNotContains('manufacturer', array_column($blocked['actions'], 'target_type'));
        self::assertNotContains('device_type', array_column($blocked['actions'], 'target_type'));
        self::assertNotContains('device', array_column($blocked['actions'], 'target_type'));

        $mapping['relation_rules'][1]['settings']['categories']['router']['hardware_confirmed'] = true;
        $confirmed = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        self::assertSame([], $confirmed['conflicts']);
        self::assertContains('manufacturer', array_column($confirmed['actions'], 'target_type'));
        self::assertContains('device_type', array_column($confirmed['actions'], 'target_type'));
        self::assertContains('device', array_column($confirmed['actions'], 'target_type'));
    }

    public function test_specific_hardware_does_not_require_a_generic_hardware_confirmation(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        foreach (['location', 'device_role', 'device'] as $type) {
            $mapping['object_policies'][$type] = ['policy' => 'migrate', 'target_type' => $type];
        }
        $mapping['relation_rules'] = [
            ['id' => 'location', 'relation' => 'location_classification', 'enabled' => true, 'settings' => [
                'locations' => ['site' => ['kind' => 'site', 'name' => 'Lab', 'slug' => 'lab', 'approved' => true]],
            ]],
            ['id' => 'device', 'relation' => 'device_defaults', 'enabled' => true, 'settings' => [
                'categories' => ['router' => [
                    'manufacturer' => ['name' => 'Acme Networks', 'slug' => 'acme', 'approved' => true],
                    'device_type' => ['model' => 'Edge 1000', 'slug' => 'edge-1000', 'approved' => true],
                    'interface_type' => '1000base-t',
                ]],
            ]],
        ];
        $source = ['objects' => [
            'locations' => [$this->source('location', 'site', ['name' => 'Lab'])],
            'device_roles' => [$this->source('device_role', 'router', ['name' => 'Router'])],
            'devices' => [$this->source('device', 'edge-01', ['name' => 'edge-01', 'category_source_id' => 'router', 'location_source_id' => 'site'])],
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);

        self::assertSame([], $result['conflicts']);
        self::assertContains('device', array_column($result['actions'], 'target_type'));
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
