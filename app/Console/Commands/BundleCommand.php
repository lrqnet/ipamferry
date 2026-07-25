<?php

namespace App\Console\Commands;

use App\Domain\Migration\BundleBuilder;
use RuntimeException;
use Throwable;

class BundleCommand extends PlannedMigrationCommand
{
    protected $signature = 'ipamferry:bundle
        {project : Migration project ID}
        {--plan= : Exact plan ID}
        {--output= : Destination ZIP path}
        {--force : Overwrite an existing destination}';

    protected $description = 'Create an offline audit bundle for an exact migration plan.';

    public function handle(BundleBuilder $builder): int
    {
        try {
            $project = $this->project();
            $plan = $this->plan($project);
            $generated = $builder->build($project, $plan);
            $output = $this->option('output');
            if (! is_string($output) || $output === '') {
                $this->line($generated);

                return self::SUCCESS;
            }

            $destination = str_starts_with($output, DIRECTORY_SEPARATOR)
                ? $output
                : getcwd().DIRECTORY_SEPARATOR.$output;
            if (file_exists($destination) && ! $this->option('force')) {
                throw new RuntimeException('The output file already exists. Use --force to overwrite it.');
            }
            if (! is_dir(dirname($destination)) || ! is_writable(dirname($destination))) {
                throw new RuntimeException('The output directory is not writable.');
            }
            if (! copy($generated, $destination)) {
                throw new RuntimeException('Unable to copy the generated bundle to the output path.');
            }
            $this->components->info("Bundle written to {$destination}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
