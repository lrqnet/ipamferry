<?php

namespace Tests\Unit;

use App\Domain\Migration\SnapshotFingerprint;
use Tests\TestCase;

class SnapshotFingerprintTest extends TestCase
{
    public function test_runtime_timestamps_do_not_change_snapshot_identity(): void
    {
        $first = [
            'discovered_at' => '2026-07-25T10:00:00Z',
            'normalized_at' => '2026-07-25T10:00:01Z',
            'objects' => ['vrfs' => [['id' => 1, 'name' => 'Blue']]],
        ];
        $second = [
            'discovered_at' => '2026-07-25T11:00:00Z',
            'normalized_at' => '2026-07-25T11:00:01Z',
            'objects' => ['vrfs' => [['id' => 1, 'name' => 'Blue']]],
        ];

        self::assertSame(SnapshotFingerprint::make($first), SnapshotFingerprint::make($second));
    }
}
