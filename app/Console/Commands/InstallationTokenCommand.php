<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class InstallationTokenCommand extends Command
{
    protected $signature = 'ipamferry:installation-token';

    protected $description = 'Print the one-time token required to claim a new installation.';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->components->error('Installation has already been claimed.');

            return self::FAILURE;
        }

        $token = trim((string) @file_get_contents('/run/ipamferry-secrets/installation_token'));
        if ($token === '') {
            $this->components->error('Installation token is unavailable.');

            return self::FAILURE;
        }

        $this->line($token);

        return self::SUCCESS;
    }
}
