<?php

namespace App\Console\Commands;

use App\Domain\Migration\NetBoxClient;
use App\Domain\Migration\PhpIpamClient;
use Illuminate\Support\Facades\DB;
use Throwable;

class DoctorCommand extends MigrationCommand
{
    protected $signature = 'ipamferry:doctor';

    protected $description = 'Check runtime prerequisites and optional API connectivity.';

    public function handle(): int
    {
        try {
            DB::select('select 1');
            $this->components->info('Database connection is healthy.');
            foreach (['intl', 'pdo', 'zip'] as $extension) {
                if (! extension_loaded($extension)) {
                    $this->components->error("Required PHP extension {$extension} is missing.");

                    return self::FAILURE;
                }
            }
            $this->components->info('Required PHP extensions are loaded.');

            if (getenv('PHPIPAM_URL') !== false || getenv('PHPIPAM_TOKEN') !== false) {
                $source = (new PhpIpamClient(
                    $this->setting('PHPIPAM_URL'),
                    $this->setting('PHPIPAM_APP_ID'),
                    $this->credential('PHPIPAM_TOKEN'),
                ))->inspect();
                $this->components->info('phpIPAM API is reachable. Version: '.($source['version'] ?? 'not reported'));
            } else {
                $this->components->warn('phpIPAM connectivity skipped; set PHPIPAM_URL, PHPIPAM_APP_ID and PHPIPAM_TOKEN to test it.');
            }

            if (getenv('NETBOX_URL') !== false || getenv('NETBOX_TOKEN') !== false) {
                $target = (new NetBoxClient(
                    $this->setting('NETBOX_URL'),
                    $this->credential('NETBOX_TOKEN'),
                ))->inspect();
                $this->components->info('NetBox API is reachable. Version: '.($target['version'] ?? 'not reported'));
            } else {
                $this->components->warn('NetBox connectivity skipped; set NETBOX_URL and NETBOX_TOKEN to test it.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
