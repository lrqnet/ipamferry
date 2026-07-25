<?php

return ['default' => env('CACHE_STORE', 'database'), 'stores' => ['database' => ['driver' => 'database', 'table' => 'cache', 'connection' => null], 'array' => ['driver' => 'array']]];
