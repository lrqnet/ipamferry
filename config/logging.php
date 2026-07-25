<?php

use Monolog\Handler\StreamHandler;

return ['default' => env('LOG_CHANNEL', 'stderr'), 'channels' => ['stderr' => ['driver' => 'monolog', 'handler' => StreamHandler::class, 'with' => ['stream' => 'php://stderr']], 'stack' => ['driver' => 'stack', 'channels' => ['stderr'], 'ignore_exceptions' => false]]];
