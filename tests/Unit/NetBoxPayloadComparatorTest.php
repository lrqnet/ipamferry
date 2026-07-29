<?php

namespace Tests\Unit;

use App\Domain\Migration\NetBoxPayloadComparator;
use PHPUnit\Framework\TestCase;

class NetBoxPayloadComparatorTest extends TestCase
{
    public function test_it_compares_write_only_id_fields_with_nested_api_objects(): void
    {
        $differences = (new NetBoxPayloadComparator)->differences([
            'termination_id' => 42,
            'tags' => [7, 8],
            'u_height' => 1,
            'position' => 40,
        ], [
            'termination' => ['id' => 42, 'display' => 'BOG'],
            'tags' => [
                ['id' => 7, 'display' => 'edge'],
                ['id' => 8, 'display' => 'managed'],
            ],
            'u_height' => 1.0,
            'position' => 40.0,
        ]);

        self::assertSame([], $differences);
    }

    public function test_it_reports_canonical_fields_and_values_for_real_differences(): void
    {
        $differences = (new NetBoxPayloadComparator)->differences([
            'termination_id' => 42,
            'custom_fields' => ['source' => 'phpIPAM'],
        ], [
            'termination' => ['id' => 41],
            'custom_fields' => ['source' => 'manual'],
        ], true);

        self::assertSame([
            'termination_id' => ['expected' => 42, 'actual' => 41],
            'custom_fields.source' => ['expected' => 'phpIPAM', 'actual' => 'manual'],
        ], $differences);
    }

    public function test_it_treats_netbox_null_empty_text_fields_as_equivalent(): void
    {
        $differences = (new NetBoxPayloadComparator)->differences([
            'dns_name' => '',
            'description' => '',
        ], [
            'dns_name' => null,
            'description' => null,
        ]);

        self::assertSame([], $differences);
    }

    public function test_it_compares_netbox_canonical_dns_and_description_values(): void
    {
        $differences = (new NetBoxPayloadComparator)->differences([
            'dns_name' => 'HOST.Example.Test.',
            'description' => ' description ',
        ], [
            'dns_name' => 'host.example.test',
            'description' => 'description',
        ]);

        self::assertSame([], $differences);
    }
}
