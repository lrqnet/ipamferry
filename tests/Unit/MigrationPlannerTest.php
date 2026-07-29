<?php

namespace Tests\Unit;

use App\Domain\Migration\MappingPolicy;
use App\Domain\Migration\MigrationPlanner;
use Tests\TestCase;

class MigrationPlannerTest extends TestCase
{
    public function test_it_uses_contextual_natural_keys_and_orders_dependencies(): void
    {
        $source = $this->source([
            'vrfs' => [
                $this->object('vrf', 'vrf-a', ['name' => 'Blue', 'rd' => '65000:1']),
                $this->object('vrf', 'vrf-b', ['name' => 'Red', 'rd' => '65000:2']),
            ],
            'vlan_groups' => [
                $this->object('vlan_group', 'group-a', ['name' => 'Campus A']),
                $this->object('vlan_group', 'group-b', ['name' => 'Campus B']),
            ],
            'vlans' => [
                $this->object('vlan', 'vlan-a', ['vid' => 100, 'name' => 'Users A', 'vlan_group_source_id' => 'group-a']),
                $this->object('vlan', 'vlan-b', ['vid' => 100, 'name' => 'Users B', 'vlan_group_source_id' => 'group-b']),
            ],
            'prefixes' => [
                $this->object('prefix', 'prefix-a', ['prefix' => '10.0.0.0/24', 'vrf_source_id' => 'vrf-a']),
                $this->object('prefix', 'prefix-b', ['prefix' => '10.0.0.0/24', 'vrf_source_id' => 'vrf-b']),
            ],
        ]);
        $target = ['objects' => [
            'vrfs' => [['id' => 10, 'name' => 'Blue', 'rd' => '65000:1']],
            'vlan_groups' => [['id' => 20, 'name' => 'Campus A', 'scope' => null]],
            'vlans' => [['id' => 30, 'vid' => 100, 'name' => 'Users A', 'group' => ['id' => 20], 'status' => ['value' => 'active']]],
            'prefixes' => [['id' => 40, 'prefix' => '10.0.0.0/24', 'vrf' => ['id' => 10], 'status' => ['value' => 'active']]],
            'ip_addresses' => [],
            'custom_fields' => [],
        ]];

        $result = (new MigrationPlanner)->plan($source, $target, MappingPolicy::defaults());
        $actions = collect($result['actions'])->keyBy(fn (array $action): string => "{$action['source_type']}:{$action['source_id']}");

        self::assertSame([], $result['conflicts']);
        self::assertSame('reuse', $actions['vrf:vrf-a']['operation']);
        self::assertSame('create', $actions['vrf:vrf-b']['operation']);
        self::assertSame('reuse', $actions['vlan:vlan-a']['operation']);
        self::assertSame('create', $actions['vlan:vlan-b']['operation']);
        self::assertSame('reuse', $actions['prefix:prefix-a']['operation']);
        self::assertSame('create', $actions['prefix:prefix-b']['operation']);
        self::assertSame(10, $actions['prefix:prefix-a']['natural_key']['vrf_id']);
        self::assertArrayHasKey('$ref', $actions['prefix:prefix-b']['natural_key']['vrf_id']);
        self::assertContains(
            $actions['vrf:vrf-b']['action_key'],
            $actions['prefix:prefix-b']['dependencies'],
        );
        self::assertLessThan(
            array_search($actions['prefix:prefix-b']['action_key'], array_column($result['actions'], 'action_key'), true),
            array_search($actions['vrf:vrf-b']['action_key'], array_column($result['actions'], 'action_key'), true),
        );
    }

    public function test_it_plans_custom_field_creation_before_dependent_objects(): void
    {
        $source = $this->source([
            'prefixes' => [
                $this->object('prefix', 'prefix-a', [
                    'prefix' => '192.0.2.0/24',
                    'legacy' => ['rack_code' => 'R-17'],
                ]),
            ],
        ]);
        $mapping = [
            'custom_fields' => [[
                'source_type' => 'prefix',
                'source_field' => 'rack_code',
                'target' => 'legacy_rack',
                'action' => 'copy',
                'data_type' => 'text',
            ]],
        ];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        $customField = collect($result['actions'])->firstWhere('target_type', 'custom_field');
        $prefix = collect($result['actions'])->firstWhere('target_type', 'prefix');

        self::assertNotNull($customField);
        self::assertSame('create', $customField['operation']);
        self::assertSame(['ipam.prefix'], $customField['payload']['object_types']);
        self::assertSame(['legacy_rack' => 'R-17'], $prefix['payload']['custom_fields']);
        self::assertContains($customField['action_key'], $prefix['dependencies']);
    }

    public function test_it_blocks_missing_dependencies_and_incompatible_custom_fields(): void
    {
        $source = $this->source([
            'vlans' => [
                $this->object('vlan', 'vlan-a', ['vid' => 100, 'name' => 'Users', 'vlan_group_source_id' => 'missing']),
            ],
            'prefixes' => [
                $this->object('prefix', 'prefix-a', ['prefix' => '192.0.2.0/24', 'legacy' => ['code' => 7]]),
            ],
        ]);
        $target = ['objects' => ['custom_fields' => [[
            'id' => 1,
            'name' => 'legacy_code',
            'type' => ['value' => 'boolean'],
            'object_types' => ['ipam.prefix'],
        ]]]];
        $mapping = ['custom_fields' => [[
            'source_type' => 'prefix',
            'source_field' => 'code',
            'target' => 'legacy_code',
            'action' => 'copy',
            'data_type' => 'integer',
        ]]];

        $result = (new MigrationPlanner)->plan($source, $target, $mapping);

        self::assertContains('missing_dependency', array_column($result['conflicts'], 'reason'));
        self::assertContains('custom_field_type_mismatch', array_column($result['conflicts'], 'reason'));
    }

    public function test_updates_are_opt_in_and_contain_only_allowed_fields(): void
    {
        $source = $this->source([
            'prefixes' => [
                $this->object('prefix', 'prefix-a', ['prefix' => '198.51.100.0/24', 'description' => 'Desired']),
            ],
        ]);
        $target = ['objects' => [
            'prefixes' => [[
                'id' => 7,
                'prefix' => '198.51.100.0/24',
                'vrf' => null,
                'description' => 'Existing',
                'status' => ['value' => 'active'],
                'is_pool' => false,
                'mark_utilized' => false,
            ]],
        ]];

        $reuse = (new MigrationPlanner)->plan($source, $target, MappingPolicy::defaults());
        $update = (new MigrationPlanner)->plan($source, $target, ['updates' => ['prefix' => ['description']]]);

        self::assertSame('reuse', $reuse['actions'][0]['operation']);
        self::assertSame('update', $update['actions'][0]['operation']);
        self::assertSame(['description' => 'Desired'], $update['actions'][0]['payload']);
    }

    public function test_persistent_object_links_take_precedence_over_changed_natural_keys(): void
    {
        $source = $this->source([
            'vrfs' => [
                $this->object('vrf', 'vrf-a', ['name' => 'Renamed', 'rd' => '65000:99']),
            ],
        ]);
        $target = ['objects' => [
            'vrfs' => [[
                'id' => 77,
                'name' => 'Original',
                'rd' => '65000:1',
                'description' => '',
                'last_updated' => '2026-07-25T12:00:00Z',
            ]],
        ]];
        $links = [[
            'source_type' => 'vrf',
            'source_id' => 'vrf-a',
            'target_type' => 'vrf',
            'target_id' => 77,
        ]];

        $result = (new MigrationPlanner)->plan($source, $target, MappingPolicy::defaults(), $links);

        self::assertSame([], $result['conflicts']);
        self::assertSame('reuse', $result['actions'][0]['operation']);
        self::assertSame('object_link', $result['actions'][0]['matched_by']);
        self::assertSame(77, $result['actions'][0]['target_id']);
        self::assertSame('2026-07-25T12:00:00Z', $result['actions'][0]['target_last_updated']);
    }

    public function test_missing_linked_targets_and_invalid_custom_field_values_are_conflicts(): void
    {
        $source = $this->source([
            'prefixes' => [
                $this->object('prefix', 'prefix-a', [
                    'prefix' => '203.0.113.0/24',
                    'legacy' => ['weight' => 'not-an-integer'],
                ]),
            ],
        ]);
        $mapping = ['custom_fields' => [[
            'source_type' => 'prefix',
            'source_field' => 'weight',
            'target' => 'legacy_weight',
            'action' => 'copy',
            'data_type' => 'integer',
        ]]];
        $links = [[
            'source_type' => 'prefix',
            'source_id' => 'prefix-a',
            'target_type' => 'prefix',
            'target_id' => 404,
        ]];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping, $links);

        self::assertContains('invalid_custom_field_value', array_column($result['conflicts'], 'reason'));
        self::assertContains('linked_target_missing', array_column($result['conflicts'], 'reason'));
    }

    public function test_netbox_write_schema_rejects_invalid_status_before_apply(): void
    {
        $source = $this->source([
            'prefixes' => [
                $this->object('prefix', 'prefix-a', ['prefix' => '203.0.113.0/24', 'source_status' => 'legacy']),
            ],
        ]);
        $target = [
            'objects' => [],
            'write_schema' => [
                'prefix' => [
                    'prefix' => ['required' => true],
                    'status' => ['choices' => [
                        ['value' => 'active'],
                        ['value' => 'reserved'],
                    ]],
                ],
            ],
        ];

        $result = (new MigrationPlanner)->plan($source, $target, [
            'statuses' => ['prefix' => ['legacy' => 'invalid-status', 'default' => 'active']],
        ]);

        self::assertContains('unsupported_target_choice', array_column($result['conflicts'], 'reason'));
    }

    public function test_netbox_write_schema_rejects_length_constraints_before_apply(): void
    {
        $source = $this->source([
            'vlans' => [
                $this->object('vlan', 'invalid', ['vid' => 4094, 'name' => str_repeat('x', 101)]),
            ],
        ]);
        $target = [
            'objects' => [],
            'write_schema' => [
                'vlan' => [
                    'vid' => ['required' => true, 'min_value' => 1, 'max_value' => 4094],
                    'name' => ['required' => true, 'max_length' => 100],
                    'status' => ['required' => true],
                ],
            ],
        ];

        $result = (new MigrationPlanner)->plan($source, $target, MappingPolicy::defaults());

        self::assertContains('target_field_constraint', array_column($result['conflicts'], 'reason'));
        self::assertSame([], $result['actions']);
    }

    public function test_payload_text_accepts_unicode_html_markdown_and_newlines_at_the_limit_but_blocks_overflow_and_controls(): void
    {
        $atLimit = "# Heading\r\n**unicode** 🚢 <em>safe</em> / \\\"".str_repeat('x', 50);
        $source = $this->source([
            'prefixes' => [$this->object('prefix', 'safe', [
                'prefix' => '2001:db8:feed::/64',
                'description' => $atLimit,
            ])],
        ]);
        $target = ['objects' => [], 'write_schema' => [
            'prefix' => [
                'prefix' => ['required' => true],
                'status' => ['required' => true],
                'description' => ['max_length' => mb_strlen($atLimit)],
            ],
        ]];

        $accepted = (new MigrationPlanner)->plan($source, $target, MappingPolicy::defaults());
        self::assertSame([], $accepted['conflicts']);

        $overflow = $source;
        $overflow['objects']['prefixes'][0]['description'] .= 'x';
        $rejected = (new MigrationPlanner)->plan($overflow, $target, MappingPolicy::defaults());
        self::assertContains('target_field_constraint', array_column($rejected['conflicts'], 'reason'));

        $control = $source;
        $control['objects']['prefixes'][0]['description'] = "valid\ntext\0";
        $blocked = (new MigrationPlanner)->plan($control, $target, MappingPolicy::defaults());
        self::assertContains('target_text_control_character', array_column($blocked['conflicts'], 'reason'));
    }

    public function test_existing_alternate_unique_identities_are_blocking_conflicts(): void
    {
        $source = $this->source([
            'vlan_groups' => [
                $this->object('vlan_group', 'new-group', ['name' => 'New Campus']),
                $this->object('vlan_group', 'existing-group', ['name' => 'Existing Campus']),
            ],
            'vlans' => [
                $this->object('vlan', 'new-vlan', [
                    'vid' => 200,
                    'name' => 'Existing Users',
                    'vlan_group_source_id' => 'existing-group',
                ]),
            ],
        ]);
        $target = ['objects' => [
            'vlan_groups' => [
                ['id' => 10, 'name' => 'Different Group', 'slug' => 'new-campus', 'scope' => null],
                ['id' => 20, 'name' => 'Existing Campus', 'slug' => 'existing-campus', 'scope' => null],
            ],
            'vlans' => [[
                'id' => 30,
                'vid' => 100,
                'name' => 'Existing Users',
                'group' => ['id' => 20],
            ]],
        ]];

        $result = (new MigrationPlanner)->plan($source, $target, MappingPolicy::defaults());
        $collisions = array_values(array_filter(
            $result['conflicts'],
            fn (array $conflict): bool => $conflict['reason'] === 'target_identity_collision',
        ));

        self::assertCount(2, $collisions);
        self::assertEqualsCanonicalizing(
            ['scope_slug', 'group_name'],
            array_column($collisions, 'identity_kind'),
        );
    }

    public function test_duplicate_source_and_target_claims_are_blocking_conflicts(): void
    {
        $source = $this->source([
            'vrfs' => [
                $this->object('vrf', 'first', ['name' => 'Blue', 'rd' => '65000:1']),
                $this->object('vrf', 'second', ['name' => 'Renamed', 'rd' => '65000:1']),
            ],
            'vlans' => [
                $this->object('vlan', 'duplicate', ['vid' => 100, 'name' => 'First']),
                $this->object('vlan', 'duplicate', ['vid' => 200, 'name' => 'Second']),
            ],
        ]);

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], MappingPolicy::defaults());
        $reasons = array_column($result['conflicts'], 'reason');

        self::assertContains('duplicate_target_claim', $reasons);
        self::assertContains('duplicate_source_identity', $reasons);
    }

    public function test_missing_write_metadata_blocks_mutations_but_not_safe_reuse(): void
    {
        $source = $this->source([
            'vrfs' => [
                $this->object('vrf', 'existing', ['name' => 'Blue', 'rd' => '65000:1']),
                $this->object('vrf', 'new', ['name' => 'Red', 'rd' => '65000:2']),
            ],
        ]);
        $target = [
            'objects' => [
                'vrfs' => [['id' => 1, 'name' => 'Blue', 'rd' => '65000:1', 'description' => '']],
            ],
            'write_schema' => ['vrf' => null],
        ];

        $result = (new MigrationPlanner)->plan($source, $target, MappingPolicy::defaults());
        $actions = collect($result['actions'])->keyBy('source_id');

        self::assertSame('reuse', $actions['existing']['operation']);
        self::assertArrayNotHasKey('new', $actions);
        self::assertContains('target_write_schema_unavailable', array_column($result['conflicts'], 'reason'));
    }

    public function test_custom_field_value_updates_are_explicit_and_legacy_records_are_preserved(): void
    {
        $source = $this->source([
            'prefixes' => [
                $this->object('prefix', 'prefix-a', [
                    'prefix' => '192.0.2.0/24',
                    'legacy' => ['asset_code' => 'NEW'],
                ]),
            ],
        ]);
        $target = ['objects' => [
            'prefixes' => [[
                'id' => 1,
                'prefix' => '192.0.2.0/24',
                'vrf' => null,
                'status' => ['value' => 'active'],
                'description' => '',
                'is_pool' => false,
                'mark_utilized' => false,
                'custom_fields' => ['legacy_asset' => 'OLD'],
            ]],
            'custom_fields' => [[
                'id' => 2,
                'name' => 'legacy_asset',
                'type' => ['value' => 'text'],
                'object_types' => ['ipam.prefix'],
                'label' => 'legacy_asset',
                'description' => 'Migrated by IpamFerry',
            ]],
        ]];
        $mapping = [
            'updates' => ['prefix' => ['custom_fields']],
            'custom_fields' => [[
                'source_type' => 'prefix',
                'source_field' => 'asset_code',
                'target' => 'legacy_asset',
                'action' => 'copy',
                'data_type' => 'text',
            ]],
        ];

        $result = (new MigrationPlanner)->plan($source, $target, $mapping);
        $prefix = collect($result['actions'])->firstWhere('target_type', 'prefix');

        self::assertSame('update', $prefix['operation']);
        self::assertSame(['custom_fields' => ['legacy_asset' => 'NEW']], $prefix['payload']);
        self::assertSame('NEW', $result['preservation']['source_records']['prefixes'][0]['legacy']['asset_code']);
    }

    public function test_it_omits_orphaned_tag_references_when_no_tag_definition_exists(): void
    {
        $source = $this->source([
            'prefixes' => [$this->object('prefix', 'prefix-a', [
                'prefix' => '198.51.100.0/24',
                'tag_source_id' => '2',
            ])],
        ]);
        $mapping = MappingPolicy::v2Defaults();
        $mapping['object_policies']['prefix'] = ['policy' => 'migrate', 'target_type' => 'prefix'];
        $mapping['object_policies']['tag'] = ['policy' => 'migrate', 'target_type' => 'tag'];

        $result = (new MigrationPlanner)->plan($source, ['objects' => []], $mapping);
        $prefix = collect($result['actions'])->firstWhere('target_type', 'prefix');

        self::assertNotNull($prefix);
        self::assertSame([], $result['conflicts']);
        self::assertSame([], $prefix['payload']['tags']);
    }

    private function source(array $objects): array
    {
        return ['objects' => [
            'vrfs' => [],
            'vlan_groups' => [],
            'vlans' => [],
            'prefixes' => [],
            'ip_addresses' => [],
            ...$objects,
        ]];
    }

    private function object(string $type, string $id, array $values): array
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
