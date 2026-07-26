<?php

namespace App\Domain\Migration;

use App\Enums\MigrationActionStatus;
use App\Enums\MigrationExecutionStatus;
use App\Enums\MigrationProjectStatus;
use App\Models\MigrationActionResult;
use App\Models\MigrationExecution;
use App\Models\MigrationObjectLink;
use App\Models\MigrationPlan;
use App\Models\MigrationProject;
use DomainException;
use Throwable;

class MigrationApplier
{
    public function __construct(
        private readonly PlanIntegrity $integrity,
        private readonly MigrationAudit $audit,
        private readonly MigrationOperationLock $operations,
        private readonly NetBoxPayloadComparator $payloadComparator,
    ) {}

    public function apply(
        MigrationProject $project,
        MigrationPlan $plan,
        string $netBoxUrl,
        string $netBoxToken,
        ?int $userId = null,
        int $maximumActions = 0,
    ): MigrationExecution {
        $this->assertApplicable($project, $plan);
        $lock = $this->operations->acquire($project);

        try {
            $this->operations->assertPlanOperationAllowed($project, $plan);
            $this->integrity->assert($plan);
            $client = new NetBoxClient($netBoxUrl, $netBoxToken);
            $targetInstance = $client->instance();
            if (! hash_equals((string) $plan->target_instance_fingerprint, (string) $targetInstance['fingerprint'])) {
                throw new DomainException('This plan is bound to a different NetBox instance or API version.');
            }
            $execution = $this->execution($project, $plan, $userId);
            if ($execution->status === MigrationExecutionStatus::Applied || $execution->status === MigrationExecutionStatus::Verified) {
                return $execution;
            }
            if ($execution->status === MigrationExecutionStatus::Verifying) {
                throw new DomainException('This migration execution is currently being verified.');
            }

            $execution->update([
                'status' => MigrationExecutionStatus::Applying,
                'started_at' => $execution->started_at ?? now(),
                'last_error' => null,
            ]);
            $project->update(['status' => MigrationProjectStatus::Applying, 'last_error' => null]);

            $processed = 0;
            foreach ($plan->actions as $index => $action) {
                if ($maximumActions > 0 && $processed >= $maximumActions) {
                    break;
                }
                if ($this->applyAction($project, $plan, $execution, $client, $action, $index)) {
                    $processed++;
                }
            }

            $summary = $this->summary($execution, count($plan->actions));
            if ($summary['remaining'] > 0) {
                $execution->update([
                    'status' => MigrationExecutionStatus::Applying,
                    'summary' => $summary,
                ]);
                $project->update(['status' => MigrationProjectStatus::Applying]);
                $this->audit->record(
                    $project,
                    'apply.checkpoint',
                    $summary,
                    $userId,
                    $plan->id,
                    $execution->id,
                );

                return $execution->fresh(['actionResults']);
            }

            $execution->update([
                'status' => MigrationExecutionStatus::Applied,
                'completed_at' => now(),
                'summary' => $summary,
            ]);
            $plan->forceFill(['applied_at' => $plan->applied_at ?? now()])->save();
            $project->update(['status' => MigrationProjectStatus::Applied]);
            $this->audit->record(
                $project,
                'apply.completed',
                $summary,
                $userId,
                $plan->id,
                $execution->id,
            );

            return $execution->fresh(['actionResults']);
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 2000);
            if (isset($execution)) {
                $completed = $execution->actionResults()
                    ->whereIn('status', array_map(
                        fn (MigrationActionStatus $status): string => $status->value,
                        [
                            MigrationActionStatus::Created,
                            MigrationActionStatus::Reused,
                            MigrationActionStatus::Updated,
                            MigrationActionStatus::Skipped,
                        ],
                    ))
                    ->exists();
                $execution->update([
                    'status' => MigrationExecutionStatus::Failed,
                    'last_error' => $message,
                    'summary' => $this->summary($execution, count($plan->actions)),
                ]);
                $project->update([
                    'status' => $completed ? MigrationProjectStatus::PartiallyApplied : MigrationProjectStatus::Failed,
                    'last_error' => $message,
                ]);
                $this->audit->record(
                    $project,
                    'apply.failed',
                    [
                        'error_type' => $exception::class,
                        ...$this->summary($execution, count($plan->actions)),
                    ],
                    $userId,
                    $plan->id,
                    $execution->id,
                    'error',
                );
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function applyAction(
        MigrationProject $project,
        MigrationPlan $plan,
        MigrationExecution $execution,
        NetBoxClient $client,
        array $action,
        int $index,
    ): bool {
        $result = MigrationActionResult::query()->firstOrCreate(
            ['execution_id' => $execution->id, 'action_key' => $action['action_key']],
            [
                'action_index' => $index,
                'operation' => $action['operation'],
                'status' => MigrationActionStatus::Pending,
                'target_type' => $action['target_type'],
                'target_id' => $action['target_id'] ?? null,
                'payload_hash' => $action['payload_hash'],
                'result' => [],
            ],
        );

        if ($result->status->isComplete()) {
            return false;
        }

        $result->update([
            'status' => MigrationActionStatus::Running,
            'attempts' => $result->attempts + 1,
            'started_at' => now(),
            'error' => null,
        ]);

        try {
            if ($action['operation'] === 'ignore') {
                $result->update([
                    'status' => MigrationActionStatus::Skipped,
                    'completed_at' => now(),
                    'result' => ['reason' => $action['reason'] ?? 'ignored_by_mapping'],
                ]);

                return true;
            }
            if ($action['operation'] === 'relation') {
                return $this->applyRelationAction($project, $plan, $execution, $client, $result, $action);
            }

            $payload = $this->resolveReferences($action['payload'], $execution);
            $naturalKey = $this->resolveReferences($action['natural_key'], $execution);
            $target = null;
            $detail = null;
            if (($action['target_id'] ?? null) !== null) {
                $detail = $client->detail($action['target_type'], (int) $action['target_id']);
                $target = $detail['data'];
                $expectedLastUpdated = $action['target_last_updated'] ?? null;
                $actualLastUpdated = is_array($target) ? ($target['last_updated'] ?? null) : null;
                if ($expectedLastUpdated !== null && $expectedLastUpdated !== $actualLastUpdated) {
                    if ($action['operation'] !== 'update' || $this->differences($payload, $target) !== []) {
                        throw new DomainException("The planned NetBox target changed after discovery for action {$action['action_key']}. Generate a new plan.");
                    }
                }
            } else {
                $matches = $client->findMatches($action['target_type'], $naturalKey);
                if (count($matches) > 1) {
                    throw new DomainException("NetBox now contains multiple matches for action {$action['action_key']}.");
                }
                $target = $matches[0] ?? null;
            }

            $requestId = null;
            $status = MigrationActionStatus::Reused;
            $recoveredAfterResponseLoss = false;

            if ($action['operation'] === 'create' && $target === null) {
                $response = $client->create(
                    $action['target_type'],
                    $payload,
                    $this->changelogMessage($project, $plan, $action),
                );
                $target = $response['data'];
                $requestId = $response['request_id'];
                $status = MigrationActionStatus::Created;
            } elseif ($action['operation'] === 'create') {
                if ($result->attempts <= 1) {
                    throw new DomainException("A NetBox target appeared after planning for action {$action['action_key']}. Generate a new plan.");
                }
                if ($this->differences($payload, $target) !== []) {
                    throw new DomainException("A previous create attempt has no safely recoverable target for action {$action['action_key']}.");
                }
                $recoveredAfterResponseLoss = true;
            } elseif ($action['operation'] === 'update') {
                if ($target === null) {
                    throw new DomainException("The target selected for update no longer exists for action {$action['action_key']}.");
                }
                if ($this->differences($payload, $target) !== []) {
                    $detail ??= $client->detail($action['target_type'], (int) $target['id']);
                    $response = $client->update(
                        $action['target_type'],
                        (int) $target['id'],
                        $payload,
                        $detail['etag'],
                        $this->changelogMessage($project, $plan, $action),
                    );
                    $target = $response['data'];
                    $requestId = $response['request_id'];
                    $status = MigrationActionStatus::Updated;
                } else {
                    $recoveredAfterResponseLoss = $result->attempts > 1;
                }
            } elseif ($target === null) {
                throw new DomainException("The target selected for reuse no longer exists for action {$action['action_key']}.");
            }

            $targetId = (int) ($target['id'] ?? 0);
            if ($targetId <= 0) {
                throw new DomainException("NetBox returned no target ID for action {$action['action_key']}.");
            }

            $result->update([
                'status' => $status,
                'target_id' => $targetId,
                'request_id' => $requestId,
                'result' => [
                    ...$this->safeTargetSnapshot($target),
                    'recovered_after_response_loss' => $recoveredAfterResponseLoss,
                ],
                'completed_at' => now(),
            ]);
            MigrationObjectLink::query()->updateOrCreate(
                [
                    'project_id' => $project->id,
                    'source_instance_fingerprint' => $project->source_instance['fingerprint'],
                    'source_type' => $action['source_type'],
                    'source_id' => (string) $action['source_id'],
                    'target_instance_fingerprint' => $plan->target_instance_fingerprint,
                ],
                [
                    'target_type' => $action['target_type'],
                    'target_id' => $targetId,
                    'natural_key' => CanonicalJson::encode($naturalKey),
                    'target_snapshot' => $this->safeTargetSnapshot($target),
                ],
            );

            return true;
        } catch (Throwable $exception) {
            $result->update([
                'status' => MigrationActionStatus::Failed,
                'error' => mb_substr($exception->getMessage(), 0, 2000),
                'completed_at' => now(),
            ]);
            throw $exception;
        }
    }

    private function applyRelationAction(
        MigrationProject $project,
        MigrationPlan $plan,
        MigrationExecution $execution,
        NetBoxClient $client,
        MigrationActionResult $result,
        array $action,
    ): bool {
        $targetId = $this->resolveReferences($action['subject_ref'] ?? [], $execution);
        if (! is_int($targetId) || $targetId <= 0) {
            throw new DomainException("Relation action {$action['action_key']} has no resolved subject.");
        }
        $payload = $this->resolveReferences($action['payload'], $execution);
        $detail = $client->detail($action['target_type'], $targetId);
        $target = $detail['data'];
        $requestId = null;
        $status = MigrationActionStatus::Reused;
        if ($this->differences($payload, $target) !== []) {
            $response = $client->update(
                $action['target_type'],
                $targetId,
                $payload,
                $detail['etag'],
                $this->changelogMessage($project, $plan, $action),
            );
            $target = $response['data'];
            $requestId = $response['request_id'];
            $status = MigrationActionStatus::Updated;
        }
        $result->update([
            'status' => $status,
            'target_id' => $targetId,
            'request_id' => $requestId,
            'result' => $this->safeTargetSnapshot($target),
            'completed_at' => now(),
        ]);
        MigrationObjectLink::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'source_instance_fingerprint' => $project->source_instance['fingerprint'],
                'source_type' => $action['source_type'],
                'source_id' => (string) $action['source_id'],
                'target_instance_fingerprint' => $plan->target_instance_fingerprint,
            ],
            [
                'target_type' => $action['target_type'],
                'target_id' => $targetId,
                'natural_key' => CanonicalJson::encode(['relation' => $action['relation'] ?? null]),
                'target_snapshot' => $this->safeTargetSnapshot($target),
            ],
        );

        return true;
    }

    private function execution(MigrationProject $project, MigrationPlan $plan, ?int $userId): MigrationExecution
    {
        $existing = MigrationExecution::query()
            ->where('plan_id', $plan->id)
            ->where('target_instance_fingerprint', $plan->target_instance_fingerprint)
            ->latest('id')
            ->first();

        if ($existing !== null && in_array($existing->status, [
            MigrationExecutionStatus::Pending,
            MigrationExecutionStatus::Applying,
            MigrationExecutionStatus::Failed,
            MigrationExecutionStatus::Applied,
            MigrationExecutionStatus::Verifying,
            MigrationExecutionStatus::Verified,
        ], true)) {
            return $existing;
        }

        return MigrationExecution::query()->create([
            'project_id' => $project->id,
            'plan_id' => $plan->id,
            'created_by' => $userId,
            'status' => MigrationExecutionStatus::Pending,
            'target_instance_fingerprint' => $plan->target_instance_fingerprint,
            'summary' => [],
        ]);
    }

    private function resolveReferences(mixed $value, MigrationExecution $execution): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (isset($value['$missing'])) {
            throw new DomainException("Unresolved dependency {$value['$missing']}.");
        }

        if (isset($value['$ref'])) {
            $dependency = $execution->actionResults()
                ->where('action_key', $value['$ref'])
                ->first();
            if ($dependency === null || ! $dependency->status->isComplete() || $dependency->target_id === null) {
                throw new DomainException("Dependency {$value['$ref']} has no resolved NetBox object.");
            }

            return (int) $dependency->target_id;
        }

        $resolved = [];
        foreach ($value as $key => $item) {
            $resolved[$key] = $this->resolveReferences($item, $execution);
        }

        return $resolved;
    }

    private function assertApplicable(MigrationProject $project, MigrationPlan $plan): void
    {
        if ($plan->project_id !== $project->id) {
            throw new DomainException('The selected plan does not belong to this project.');
        }
        if ($plan->approved_at === null || $plan->approved_by === null) {
            throw new DomainException('Approve this exact migration plan before applying it.');
        }
        if ($plan->conflicts !== []) {
            throw new DomainException('Resolve all migration plan conflicts before applying it.');
        }
        if ($plan->target_instance_fingerprint === null) {
            throw new DomainException('The plan is not bound to a discovered NetBox instance.');
        }
        if (! is_string($project->source_instance['fingerprint'] ?? null)
            || $project->source_instance['fingerprint'] === ''
        ) {
            throw new DomainException('The project is not bound to a discovered phpIPAM source instance.');
        }
    }

    private function changelogMessage(MigrationProject $project, MigrationPlan $plan, array $action): string
    {
        return "IpamFerry project {$project->id}, plan {$plan->id}, action ".substr($action['action_key'], 0, 12);
    }

    private function safeTargetSnapshot(array $target): array
    {
        return array_intersect_key($target, array_flip([
            'id',
            'url',
            'display',
            'name',
            'rd',
            'vid',
            'prefix',
            'address',
            'status',
            'type',
            'last_updated',
        ]));
    }

    private function differences(array $expected, array $actual): array
    {
        return $this->payloadComparator->differences($expected, $actual);
    }

    private function summary(MigrationExecution $execution, int $total): array
    {
        $byStatus = $execution->actionResults()
            ->reorder()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        $completed = array_sum(array_intersect_key($byStatus, array_flip([
            MigrationActionStatus::Created->value,
            MigrationActionStatus::Reused->value,
            MigrationActionStatus::Updated->value,
            MigrationActionStatus::Skipped->value,
        ])));

        return [
            'total' => $total,
            'completed' => $completed,
            'remaining' => max(0, $total - $completed),
            'by_status' => $byStatus,
        ];
    }
}
