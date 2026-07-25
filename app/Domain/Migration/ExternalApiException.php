<?php

namespace App\Domain\Migration;

use RuntimeException;

class ExternalApiException extends RuntimeException
{
    public function __construct(
        public readonly string $system,
        public readonly string $operation,
        public readonly ?int $httpStatus = null,
    ) {
        $suffix = $httpStatus === null ? '' : " (HTTP {$httpStatus})";
        parent::__construct("{$system} request failed while {$operation}{$suffix}.");
    }
}
