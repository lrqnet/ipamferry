<?php

namespace App\Console\Commands;

use App\Domain\Migration\MigrationVerifier;
use Throwable;

class VerifyCommand extends PlannedMigrationCommand
{
    protected $signature = 'ipamferry:verify
        {project : Migration project ID}
        {--plan= : Exact plan ID}';

    protected $description = 'Verify the latest applied execution using NETBOX_URL and NETBOX_TOKEN.';

    public function handle(MigrationVerifier $verifier): int
    {
        try {
            $project = $this->project();
            $plan = $this->plan($project);
            $execution = $plan->executions()->latest('id')->firstOrFail();
            $result = $verifier->verify(
                $project,
                $plan,
                $execution,
                $this->setting('NETBOX_URL'),
                $this->credential('NETBOX_TOKEN'),
            );
            if (! $result['passed']) {
                $this->components->error('Verification found '.count($result['errors']).' difference(s).');

                return self::FAILURE;
            }
            $this->components->info("Verification passed for {$result['checked']} objects.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
