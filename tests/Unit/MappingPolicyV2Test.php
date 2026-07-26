<?php

namespace Tests\Unit;

use App\Domain\Migration\CanonicalJson;
use App\Domain\Migration\MappingPolicy;
use PHPUnit\Framework\TestCase;

class MappingPolicyV2Test extends TestCase
{
    public function test_v1_upgrade_is_explicit_valid_and_canonical(): void
    {
        $v1 = MappingPolicy::defaults();
        $v1['ignore_types'] = ['vlan'];
        $v1['custom_fields'] = [[
            'source_type' => 'prefix',
            'source_field' => 'owner',
            'target' => 'phpipam_owner',
            'action' => 'copy',
            'data_type' => 'text',
        ]];

        $policy = new MappingPolicy($v1);
        $upgraded = $policy->upgraded();

        self::assertSame(1, $policy->all()['schema_version']);
        self::assertSame(2, $upgraded['schema_version']);
        self::assertSame('ignore', $upgraded['object_policies']['vlan']['policy']);
        self::assertMatchesRegularExpression('/^field-[a-f0-9]{16}$/', $upgraded['field_rules'][0]['id']);
        self::assertSame([], (new MappingPolicy($upgraded))->validationIssues());
    }

    public function test_rule_order_does_not_change_the_mapping_fingerprint(): void
    {
        $first = MappingPolicy::v2Defaults();
        $first['field_rules'] = [
            ['id' => 'field-z', 'source_type' => 'prefix', 'source_field' => 'description', 'target' => 'description_copy', 'action' => 'copy'],
            ['id' => 'field-a', 'source_type' => 'prefix', 'source_field' => 'owner', 'target' => 'owner_copy', 'action' => 'copy'],
        ];
        $second = $first;
        $second['field_rules'] = array_reverse($second['field_rules']);

        self::assertSame(
            CanonicalJson::fingerprint((new MappingPolicy($first))->all()),
            CanonicalJson::fingerprint((new MappingPolicy($second))->all()),
        );
    }

    public function test_v2_errors_include_canonical_codes_and_json_pointers(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['field_rules'] = [[
            'id' => 'Bad id',
            'source_type' => 'prefix',
            'action' => 'copy',
            'target' => 'Not Valid',
        ]];
        $mapping['object_policies']['prefix']['target_type'] = 'prefixes';

        $issues = (new MappingPolicy($mapping))->validationIssues();

        self::assertContains('mapping.invalid_rule_id', array_column($issues, 'code'));
        self::assertContains('/field_rules/0/id', array_column($issues, 'pointer'));
        self::assertContains('/field_rules/0/source_field', array_column($issues, 'pointer'));
        self::assertContains('/field_rules/0/target', array_column($issues, 'pointer'));
        self::assertContains('/object_policies/prefix/target_type', array_column($issues, 'pointer'));
    }

    public function test_reference_rules_require_natural_keys_and_forbid_netbox_numeric_ids(): void
    {
        $mapping = MappingPolicy::v2Defaults();
        $mapping['reference_rules'][] = [
            'id' => 'reference-device-site',
            'source_type' => 'device',
            'source_field' => 'location_source_id',
            'target_type' => 'site',
            'target_field' => 'site_id',
            'match' => 'numeric_id',
        ];

        $issues = (new MappingPolicy($mapping))->validationIssues();

        self::assertContains('mapping.reference_natural_key_required', array_column($issues, 'code'));
        self::assertContains('mapping.reference_numeric_id_forbidden', array_column($issues, 'code'));
        self::assertContains('/reference_rules/0/target_field', array_column($issues, 'pointer'));
    }
}
