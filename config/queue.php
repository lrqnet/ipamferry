<?php

return ['default' => env('QUEUE_CONNECTION', 'database'), 'connections' => ['database' => ['driver' => 'database', 'connection' => null, 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]], 'failed' => ['driver' => 'database-uuids', 'database' => env('DB_CONNECTION', 'pgsql'), 'table' => 'failed_jobs']];
