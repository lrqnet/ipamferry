<?php

namespace App\Domain\Migration;

use App\Enums\MigrationExecutionStatus;
use App\Models\MigrationExecution;
use App\Models\MigrationPlan;
use App\Models\MigrationProject;
use DomainException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final class MigrationOperationLock
{
    public function acquire(MigrationProject $project): Lock
    {
        $lock = Cache::lock(
            "ipamferry:project:{$project->id}:operation",
            max(60, (int) config('ipamferry.operation_lock_seconds')),
        );
        if (! $lock->get()) {
            throw new DomainException('Another migration operation is already running for this project.');
        }

        return $lock;
    }

    public function assertDefinitionMutable(MigrationProject $project): void
    {
        $execution = $this->latestExecution($project);
        if ($execution !== null && $execution->status !== MigrationExecutionStatus::Verified) {
            throw new DomainException(
                "Execution {$execution->id} must be resumed and verified before discovery, mapping, or planning can change.",
            );
        }
    }

    public function assertPlanOperationAllowed(MigrationProject $project, MigrationPlan $plan): void
    {
        $execution = $this->latestExecution($project);
        if ($execution !== null
            && $execution->plan_id !== $plan->id
            && $execution->status !== MigrationExecutionStatus::Verified
        ) {
            throw new DomainException(
                "Execution {$execution->id} for another plan must be completed and verified first.",
            );
        }
    }

    private function latestExecution(MigrationProject $project): ?MigrationExecution
    {
        return $project->executions()->latest('id')->first();
    }
}
