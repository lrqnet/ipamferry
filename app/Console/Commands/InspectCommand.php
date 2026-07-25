<?php

namespace App\Console\Commands;

use Throwable;

class InspectCommand extends ProjectMigrationCommand
{
    protected $signature = 'ipamferry:inspect {project : Migration project ID}';

    protected $description = 'Inspect a migration project source and target snapshot.';

    public function handle(): int
    {
        try {
            $project = $this->project();
            $this->table(['Field', 'Value'], [
                ['Project', "{$project->id} — {$project->name}"],
                ['Status', $project->status->value],
                ['Source kind', $project->source_kind],
                ['Source version', $project->source_instance['version'] ?? 'not reported'],
                ['NetBox version', $project->target_instance['version'] ?? 'not reported'],
                ['Locale', $project->locale],
            ]);
            $counts = [];
            foreach ($project->source_snapshot['objects'] ?? [] as $type => $objects) {
                $counts[] = [$type, is_array($objects) ? count($objects) : 0];
            }
            $this->table(['Source object type', 'Count'], $counts);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
