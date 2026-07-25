<?php

namespace App\Console\Commands;

use App\Domain\Migration\MigrationApplier;
use App\Enums\MigrationExecutionStatus;
use Throwable;

class ApplyCommand extends PlannedMigrationCommand
{
    protected $signature = 'ipamferry:apply
        {project : Migration project ID}
        {--plan= : Exact approved plan ID}
        {--yes : Skip the interactive confirmation}';

    protected $description = 'Apply an exact approved migration plan using NETBOX_URL and NETBOX_TOKEN.';

    public function handle(MigrationApplier $applier): int
    {
        try {
            $project = $this->project();
            $plan = $this->plan($project, true);
            if (! $this->option('yes') && ! $this->confirm("Apply approved plan {$plan->id} to its bound NetBox instance?")) {
                $this->components->warn('Apply cancelled.');

                return self::SUCCESS;
            }

            $url = $this->setting('NETBOX_URL');
            $token = $this->credential('NETBOX_TOKEN');
            $previousCompleted = -1;
            do {
                $execution = $applier->apply($project, $plan, $url, $token);
                $completed = (int) ($execution->summary['completed'] ?? count($plan->actions));
                $total = (int) ($execution->summary['total'] ?? count($plan->actions));
                $this->line("Execution {$execution->id}: {$completed}/{$total} actions completed.");
                if ($execution->status === MigrationExecutionStatus::Applying && $completed === $previousCompleted) {
                    throw new \RuntimeException('Apply made no checkpoint progress.');
                }
                $previousCompleted = $completed;
            } while ($execution->status === MigrationExecutionStatus::Applying);

            $this->components->info("Plan {$plan->id} applied successfully.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
