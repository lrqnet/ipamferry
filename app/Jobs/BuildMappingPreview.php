<?php

namespace App\Jobs;

use App\Domain\Migration\CanonicalJson;
use App\Domain\Migration\MappingPolicy;
use App\Domain\Migration\MigrationPlanner;
use App\Domain\Migration\SnapshotFingerprint;
use App\Models\MappingPreview;
use App\Models\MigrationProject;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class BuildMappingPreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $previewId) {}

    public function handle(MigrationPlanner $planner): void
    {
        $preview = MappingPreview::query()->findOrFail($this->previewId);
        $project = MigrationProject::query()->findOrFail($preview->project_id);
        $preview->update(['status' => 'running', 'last_error' => null]);

        try {
            $this->assertCurrent($preview, $project);
            $result = $planner->plan(
                $project->source_snapshot ?? [],
                $project->target_snapshot ?? [],
                $project->mapping ?? [],
            );
            $this->assertCurrent($preview, $project->fresh());
            $preview->update([
                'status' => 'completed',
                'result' => $this->summarize($result, $project->source_snapshot ?? []),
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $preview->update([
                'status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'completed_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function assertCurrent(MappingPreview $preview, MigrationProject $project): void
    {
        $mapping = (new MappingPolicy($project->mapping ?? []))->all();
        if ($project->mapping_revision !== $preview->mapping_revision
            || ! hash_equals($preview->source_fingerprint, SnapshotFingerprint::make($project->source_snapshot ?? []))
            || ! hash_equals($preview->target_fingerprint, SnapshotFingerprint::make($project->target_snapshot ?? []))
            || ! hash_equals($preview->mapping_fingerprint, CanonicalJson::fingerprint($mapping))
        ) {
            throw new \DomainException('The discovery snapshot or mapping changed while this preview was queued.');
        }
    }

    private function summarize(array $result, array $source): array
    {
        $operations = [];
        $targetTypes = [];
        foreach ($result['actions'] ?? [] as $action) {
            $operation = (string) ($action['operation'] ?? 'unknown');
            $targetType = (string) ($action['target_type'] ?? 'unknown');
            $operations[$operation] = ($operations[$operation] ?? 0) + 1;
            $targetTypes[$targetType] = ($targetTypes[$targetType] ?? 0) + 1;
        }
        ksort($operations, SORT_STRING);
        ksort($targetTypes, SORT_STRING);
        $sourceCounts = [];
        foreach (($source['objects'] ?? []) as $type => $rows) {
            $sourceCounts[(string) $type] = is_array($rows) ? count($rows) : 0;
        }
        ksort($sourceCounts, SORT_STRING);
        $preservedCounts = [];
        foreach (($result['preservation']['unmigrated'] ?? []) as $type => $rows) {
            $preservedCounts[(string) $type] = is_array($rows) ? count($rows) : 0;
        }
        ksort($preservedCounts, SORT_STRING);

        return [
            'schema_version' => 1,
            'applicable' => false,
            'approvable' => false,
            'summary' => [
                'actions' => count($result['actions'] ?? []),
                'conflicts' => count($result['conflicts'] ?? []),
                'warnings' => count($result['warnings'] ?? []),
                'operations' => $operations,
                'target_types' => $targetTypes,
            ],
            'coverage' => [
                'source' => $sourceCounts,
                'preserved' => $preservedCounts,
            ],
            'conflicts' => array_slice(array_values($result['conflicts'] ?? []), 0, 250),
            'warnings' => array_slice(array_values($result['warnings'] ?? []), 0, 250),
            'truncated' => [
                'conflicts' => count($result['conflicts'] ?? []) > 250,
                'warnings' => count($result['warnings'] ?? []) > 250,
            ],
        ];
    }
}
