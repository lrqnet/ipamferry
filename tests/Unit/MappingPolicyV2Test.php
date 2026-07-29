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

    public function test_supported_custom_field_value_types_are_converted_without_executable_expressions(): void
    {
        $policy = MappingPolicy::v2Defaults();
        $policy['field_rules'] = [
            ['id' => 'field-text', 'source_type' => 'prefix', 'source_field' => 'text', 'target' => 'cf_text', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'text'],
            ['id' => 'field-longtext', 'source_type' => 'prefix', 'source_field' => 'longtext', 'target' => 'cf_longtext', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'longtext'],
            ['id' => 'field-int', 'source_type' => 'prefix', 'source_field' => 'integer', 'target' => 'cf_integer', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'integer'],
            ['id' => 'field-bool', 'source_type' => 'prefix', 'source_field' => 'boolean', 'target' => 'cf_boolean', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'boolean'],
            ['id' => 'field-date', 'source_type' => 'prefix', 'source_field' => 'date', 'target' => 'cf_date', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'date'],
            ['id' => 'field-url', 'source_type' => 'prefix', 'source_field' => 'url', 'target' => 'cf_url', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'url'],
            ['id' => 'field-json', 'source_type' => 'prefix', 'source_field' => 'json', 'target' => 'cf_json', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'json'],
            ['id' => 'field-decimal', 'source_type' => 'prefix', 'source_field' => 'decimal', 'target' => 'cf_decimal', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'decimal'],
        ];

        $result = (new MappingPolicy($policy))->customFieldResult('prefix', [
            'text' => 'plain text',
            'longtext' => "# Markdown\n🚢",
            'integer' => '42',
            'boolean' => 'yes',
            'date' => '2026-07-26',
            'url' => 'https://example.test/path',
            'json' => '{"key":[1,true]}',
            'decimal' => '12.50',
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame([
            'cf_text' => 'plain text',
            'cf_longtext' => "# Markdown\n🚢",
            'cf_integer' => 42,
            'cf_boolean' => true,
            'cf_date' => '2026-07-26',
            'cf_url' => 'https://example.test/path',
            'cf_json' => ['key' => [1, true]],
            'cf_decimal' => '12.50',
        ], $result['data']);

        $invalid = (new MappingPolicy($policy))->customFieldResult('prefix', [
            'text' => 'plain text', 'longtext' => 'text', 'integer' => 'not-an-int', 'boolean' => 'maybe',
            'date' => '2026-99-99', 'url' => 'not-a-url', 'json' => '{', 'decimal' => 'abc',
        ]);
        self::assertSame([
            'cf_integer',
            'cf_boolean',
            'cf_date',
            'cf_url',
            'cf_json',
            'cf_decimal',
        ], array_column($invalid['errors'], 'target'));
    }

    public function test_selection_custom_fields_require_an_approved_choice_set_and_reject_unknown_values(): void
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

        $policy = new MappingPolicy($mapping);
        self::assertSame([], $policy->validationIssues());
        self::assertSame(['environment' => 'production'], $policy->customFieldResult('prefix', ['environment' => 'production'])['data']);
        self::assertSame(['environment'], array_column($policy->customFieldResult('prefix', ['environment' => 'unknown'])['errors'], 'target'));

        unset($mapping['field_rules'][0]['choice_set']['approved']);
        self::assertContains('mapping.choice_set_approval_required', array_column((new MappingPolicy($mapping))->validationIssues(), 'code'));
    }
}
