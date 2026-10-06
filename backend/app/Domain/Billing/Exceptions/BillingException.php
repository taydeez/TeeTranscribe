<?php

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

final class BillingException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 409)
    {
        parent::__construct($message);
    }
}
