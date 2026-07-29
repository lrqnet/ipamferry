<?php

namespace Tests\Unit;

use Tests\TestCase;

class LaboratoryScenarioMatrixTest extends TestCase
{
    public function test_the_laboratory_matrix_covers_every_release_validation_domain(): void
    {
        $matrix = json_decode(
            (string) file_get_contents(base_path('tests/lab/scenario-matrix.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(1, $matrix['schema_version']);
        self::assertSame(
            [
                'cidr.ipv4-boundaries',
                'cidr.ipv6-boundaries',
                'vrf.identity-and-collision',
                'vlan.boundaries-and-groups',
                'prefix.hierarchy-status-and-folders',
                'bundle.prefix-hierarchy',
                'ip.assignment-and-primary',
                'text.encoding-and-limits',
                'custom-fields.types',
                'tenancy-and-contacts',
                'dcim.location-rack-device',
                'dcim.generic-hardware-confirmation',
                'circuits.terminations',
                'nat.safety',
                'target.drift-and-recovery',
                'approval.preservation-acknowledgement',
                'security.sanitization',
            ],
            array_column($matrix['scenarios'], 'id'),
        );
        foreach ($matrix['scenarios'] as $scenario) {
            self::assertContains($scenario['outcome'], $matrix['outcomes']);
            self::assertNotSame('', $scenario['assertion']);
        }
    }
}
