<?php

namespace App\Console\Commands;

use DomainException;
use Illuminate\Console\Command;

abstract class MigrationCommand extends Command
{
    protected function credential(string $name): string
    {
        $value = getenv($name);
        if (! is_string($value) || $value === '' || preg_match('/\s/', $value)) {
            throw new DomainException("Set a valid whitespace-free {$name} environment variable.");
        }

        return $value;
    }

    protected function setting(string $name): string
    {
        $value = getenv($name);
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException("Set the {$name} environment variable.");
        }

        return trim($value);
    }
}
