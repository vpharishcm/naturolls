<?php

namespace App\Exceptions;

use RuntimeException;

/** A business rule rejected the request; rendered as a consistent JSON error. */
class BusinessException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'business_rule',
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
