<?php

namespace App\Console\Commands;

use App\Domain\Migration\MigrationAudit;
use App\Domain\Migration\MigrationOperationLock;
use App\Domain\Migration\MigrationPlanner;
use App\Jobs\BuildMigrationPlan;
use Throwable;

class PlanCommand extends ProjectMigrationCommand
{
    protected $signature = 'ipamferry:plan {project : Migration project ID}';

    protected $description = 'Generate an immutable migration plan.';

    public function handle(MigrationPlanner $planner, MigrationOperationLock $operations): int
    {
        try {
            $project = $this->project();
            (new BuildMigrationPlan($project->id))->handle(
                $planner,
                app(MigrationAudit::class),
                $operations,
            );
            $plan = $project->plans()->latest('id')->firstOrFail();
            $this->components->info("Plan {$plan->id} generated with fingerprint {$plan->fingerprint}.");
            $this->line('Actions: '.count($plan->actions));
            $this->line('Conflicts: '.count($plan->conflicts));
            $this->line('Warnings: '.count($plan->warnings));

            return $plan->conflicts === [] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
