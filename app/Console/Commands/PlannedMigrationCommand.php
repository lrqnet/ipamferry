<?php

namespace App\Console\Commands;

use App\Models\MigrationPlan;
use App\Models\MigrationProject;

abstract class PlannedMigrationCommand extends ProjectMigrationCommand
{
    protected function plan(MigrationProject $project, bool $approved = false): MigrationPlan
    {
        $query = $project->plans()->latest('id');
        if ($approved) {
            $query->whereNotNull('approved_at')->whereNotNull('approved_by');
        }
        $planId = $this->option('plan');
        if ($planId !== null) {
            $query->whereKey((int) $planId);
        }

        return $query->firstOrFail();
    }
}
