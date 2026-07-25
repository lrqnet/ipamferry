<?php

namespace App\Domain\Migration;

final class SnapshotFingerprint
{
    public static function make(array $snapshot): string
    {
        unset($snapshot['discovered_at']);
        unset($snapshot['normalized_at']);

        return CanonicalJson::fingerprint($snapshot);
    }
}
