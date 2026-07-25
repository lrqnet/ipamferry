<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneDumpsCommand extends Command
{
    protected $signature = 'ipamferry:prune-dumps';

    protected $description = 'Remove raw phpIPAM dumps older than the configured retention period.';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) config('ipamferry.dump_retention_hours'));
        foreach (Storage::disk('local')->files('private/dumps') as $file) {
            if (Storage::disk('local')->lastModified($file) < $cutoff->getTimestamp()) {
                Storage::disk('local')->delete($file);
            }
        }

        return self::SUCCESS;
    }
}
