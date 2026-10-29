<?php

namespace App\Domain\Upload\Exceptions;

use RuntimeException;

final class UploadException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 409)
    {
        parent::__construct($message);
    }
}
