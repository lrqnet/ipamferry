<?php

namespace Tests\Unit;

use App\Domain\Migration\CompatibilityMatrix;
use PHPUnit\Framework\TestCase;

class CompatibilityMatrixTest extends TestCase
{
    public function test_supported_phpipam_and_netbox_ranges_are_explicit(): void
    {
        $matrix = new CompatibilityMatrix;

        self::assertTrue($matrix->phpIpam('1.5.0'));
        self::assertTrue($matrix->phpIpam('1.8.9'));
        self::assertFalse($matrix->phpIpam('1.4.9'));
        self::assertFalse($matrix->phpIpam('1.9.0'));
        self::assertTrue($matrix->netBox('4.4.0'));
        self::assertTrue($matrix->netBox('4.6.1'));
        self::assertFalse($matrix->netBox('4.3.9'));
        self::assertFalse($matrix->netBox('4.7.0'));
    }
}
