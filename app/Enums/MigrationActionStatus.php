<?php

namespace App\Enums;

enum MigrationActionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Created = 'created';
    case Reused = 'reused';
    case Updated = 'updated';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function isComplete(): bool
    {
        return in_array($this, [
            self::Created,
            self::Reused,
            self::Updated,
            self::Skipped,
        ], true);
    }
}
