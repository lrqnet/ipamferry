<?php

return ['default' => env('DB_CONNECTION', 'pgsql'), 'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true], 'connections' => [
    'sqlite' => ['driver' => 'sqlite', 'url' => env('DB_URL'), 'database' => env('DB_DATABASE', database_path('database.sqlite')), 'prefix' => '', 'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true)],
    'pgsql' => ['driver' => 'pgsql', 'url' => env('DB_URL'), 'host' => env('DB_HOST', '127.0.0.1'), 'port' => env('DB_PORT', '5432'), 'database' => env('DB_DATABASE', 'ipamferry'), 'username' => env('DB_USERNAME', 'ipamferry'), 'password' => env('DB_PASSWORD'), 'charset' => 'utf8', 'prefix' => '', 'schema' => 'public', 'sslmode' => env('DB_SSLMODE', 'prefer')],
]];
