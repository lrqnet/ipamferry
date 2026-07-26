<?php

namespace Tests\Unit;

use App\Domain\Migration\MappingCatalog;
use PHPUnit\Framework\TestCase;

class MappingCatalogTest extends TestCase
{
    public function test_catalog_is_bounded_truncated_and_excludes_sensitive_data(): void
    {
        $rows = [];
        for ($index = 0; $index < 110; $index++) {
            $rows[] = [
                'source_id' => (string) $index,
                'name' => "network-{$index}-".str_repeat('segment-', 30),
                'api_token' => 'never-send-'.$index,
                'legacy' => [
                    'description' => "Example {$index}",
                    'snmp_community' => 'private-'.$index,
                ],
            ];
        }
        $catalog = (new MappingCatalog)->build(
            ['objects' => ['devices' => $rows]],
            ['objects' => [], 'write_schema' => []],
        );
        $encoded = json_encode($catalog, JSON_THROW_ON_ERROR);
        $name = $catalog['source']['device']['fields']['name'];

        self::assertStringNotContainsString('api_token', $encoded);
        self::assertStringNotContainsString('snmp_community', $encoded);
        self::assertStringNotContainsString('never-send', $encoded);
        self::assertCount(5, $name['examples']);
        self::assertLessThanOrEqual(129, mb_strlen($name['examples'][0]));
        self::assertSame(100, $name['cardinality']);
        self::assertTrue($name['cardinality_limited']);
    }

    public function test_catalog_fingerprints_bind_it_to_both_snapshots(): void
    {
        $builder = new MappingCatalog;
        $source = ['objects' => ['prefixes' => [['prefix' => '10.0.0.0/24']]]];
        $target = ['objects' => ['prefixes' => []], 'write_schema' => []];
        $catalog = $builder->build($source, $target);

        self::assertTrue($builder->current($catalog, $source, $target));
        self::assertFalse($builder->current($catalog, ['objects' => []], $target));
    }

    public function test_empty_source_collections_keep_canonical_singular_types(): void
    {
        $catalog = (new MappingCatalog)->build(
            ['objects' => [
                'vrfs' => [],
                'vlan_groups' => [],
                'vlans' => [],
                'tags' => [],
                'nat_relations' => [],
            ]],
            ['objects' => [], 'write_schema' => []],
        );

        self::assertSame(
            ['nat', 'tag', 'vlan', 'vlan_group', 'vrf'],
            array_keys($catalog['source']),
        );
    }

    public function test_identity_hints_expose_only_bounded_relationship_keys(): void
    {
        $catalog = (new MappingCatalog)->build(
            ['objects' => ['devices' => [[
                'source_type' => 'device',
                'source_id' => '10',
                'name' => 'edge-01',
                'category_source_id' => '7',
                'location_source_id' => str_repeat('x', 300),
                'description' => 'must not be copied into identity hints',
            ]]]],
            ['objects' => [], 'write_schema' => []],
        );
        $hints = $catalog['source']['device']['identities'][0]['hints'];

        self::assertSame('7', $hints['category_source_id']);
        self::assertLessThanOrEqual(192, mb_strlen($hints['location_source_id']));
        self::assertArrayNotHasKey('description', $hints);
    }
}
