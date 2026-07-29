<?php

namespace App\Jobs;

use App\Domain\Migration\CanonicalJson;
use App\Domain\Migration\MappingPolicy;
use App\Domain\Migration\MigrationAudit;
use App\Domain\Migration\MigrationOperationLock;
use App\Domain\Migration\MigrationPlanner;
use App\Domain\Migration\SnapshotFingerprint;
use App\Enums\MigrationProjectStatus;
use App\Models\MigrationObjectLink;
use App\Models\MigrationPlan;
use App\Models\MigrationProject;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class BuildMigrationPlan implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $projectId,
        public readonly ?int $requestedBy = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    public function handle(
        MigrationPlanner $planner,
        MigrationAudit $audit,
        MigrationOperationLock $operations,
    ): void {
        $project = MigrationProject::query()->findOrFail($this->projectId);
        $lock = $operations->acquire($project);
        $planningStarted = false;

        try {
            $operations->assertDefinitionMutable($project);
            $project->update(['status' => MigrationProjectStatus::Planning, 'last_error' => null]);
            $planningStarted = true;
            $mapping = (new MappingPolicy($project->mapping ?? []))->all();
            $sourceFingerprint = SnapshotFingerprint::make($project->source_snapshot ?? []);
            $targetFingerprint = SnapshotFingerprint::make($project->target_snapshot ?? []);
            $mappingFingerprint = CanonicalJson::fingerprint($mapping);
            $targetInstance = $project->target_instance ?? [];
            $identityLinks = MigrationObjectLink::query()
                ->where('project_id', $project->id)
                ->where('source_instance_fingerprint', $project->source_instance['fingerprint'] ?? '')
                ->where('target_instance_fingerprint', $targetInstance['fingerprint'] ?? '')
                ->orderBy('source_type')
                ->orderBy('source_id')
                ->get([
                    'source_type',
                    'source_id',
                    'target_type',
                    'target_id',
                ])
                ->map(fn (MigrationObjectLink $link): array => [
                    'source_type' => $link->source_type,
                    'source_id' => $link->source_id,
                    'target_type' => $link->target_type,
                    'target_id' => $link->target_id,
                ])
                ->all();
            $result = $planner->plan(
                $project->source_snapshot ?? [],
                $project->target_snapshot ?? [],
                $mapping,
                $identityLinks,
            );
            $planSchema = (new MappingPolicy($mapping))->schemaVersion() === 2 ? 3 : 2;
            $fingerprint = CanonicalJson::fingerprint([
                'schema_version' => $planSchema,
                'engine_version' => config('ipamferry.version'),
                'source' => $sourceFingerprint,
                'target' => $targetFingerprint,
                'mapping' => $mappingFingerprint,
                'target_instance' => $targetInstance['fingerprint'] ?? null,
                'locale' => $project->locale,
                'identity_links' => $identityLinks,
                'plan' => $result,
            ]);
            $plan = MigrationPlan::query()->firstOrCreate(
                ['project_id' => $project->id, 'fingerprint' => $fingerprint],
                [
                    'schema_version' => $planSchema,
                    'engine_version' => config('ipamferry.version'),
                    'locale' => $project->locale,
                    'source_fingerprint' => $sourceFingerprint,
                    'target_fingerprint' => $targetFingerprint,
                    'mapping_fingerprint' => $mappingFingerprint,
                    'target_instance_fingerprint' => $targetInstance['fingerprint'] ?? null,
                    'target_instance' => $targetInstance,
                    'mapping_snapshot' => $mapping,
                    'identity_links' => $identityLinks,
                    ...$result,
                ],
            );
            $project->update(['status' => MigrationProjectStatus::Planned]);
            $audit->record(
                $project,
                'plan.generated',
                [
                    'fingerprint' => $plan->fingerprint,
                    'actions' => count($plan->actions),
                    'conflicts' => count($plan->conflicts),
                    'warnings' => count($plan->warnings),
                    'reused_existing_plan' => ! $plan->wasRecentlyCreated,
                ],
                $this->requestedBy,
                $plan->id,
            );
        } catch (Throwable $exception) {
            if ($planningStarted) {
                $project->update([
                    'status' => MigrationProjectStatus::Failed,
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                ]);
                $audit->record(
                    $project,
                    'plan.failed',
                    ['error_type' => $exception::class],
                    $this->requestedBy,
                    level: 'error',
                );
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
