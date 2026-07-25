<?php

namespace App\Console\Commands;

use App\Models\MigrationProject;

abstract class ProjectMigrationCommand extends MigrationCommand
{
    protected function project(): MigrationProject
    {
        return MigrationProject::query()->findOrFail((int) $this->argument('project'));
    }
}
