<?php

namespace App\Domain\Migration\Planners;

final class PlannerIntent
{
    public static function object(
        string $targetType,
        array $source,
        array $naturalKey,
        array $payload,
        bool $createApproved = true,
    ): array {
        return compact('targetType', 'source', 'naturalKey', 'payload', 'createApproved');
    }

    public static function reference(string $targetType, mixed $sourceId): ?array
    {
        if ($sourceId === null || $sourceId === '') {
            return null;
        }

        return ['$source_ref' => ['target_type' => $targetType, 'source_id' => (string) $sourceId]];
    }

    public static function issue(string $reason, string $sourceType, mixed $sourceId, array $context = []): array
    {
        return [
            'issue' => [
                'reason' => $reason,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                ...$context,
            ],
        ];
    }
}
