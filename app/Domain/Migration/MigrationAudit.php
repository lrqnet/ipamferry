<?php

namespace App\Domain\Migration;

use App\Models\MigrationEvent;
use App\Models\MigrationProject;

class MigrationAudit
{
    public function record(
        MigrationProject $project,
        string $kind,
        array $context = [],
        ?int $actorId = null,
        ?int $planId = null,
        ?int $executionId = null,
        string $level = 'info',
    ): MigrationEvent {
        return MigrationEvent::query()->create([
            'project_id' => $project->id,
            'actor_id' => $actorId,
            'plan_id' => $planId,
            'execution_id' => $executionId,
            'kind' => $kind,
            'level' => $level,
            'context' => $this->sanitize($context),
        ]);
    }

    private function sanitize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/(?:password|passwd|token|secret|credential|community|(?:api|access|private)[_-]?key)/i', $key)) {
                continue;
            }
            $result[$key] = $this->sanitize($item);
        }

        return $result;
    }
}
