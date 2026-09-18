<?php

namespace App\Domain\Transcriber\Exceptions;

use RuntimeException;

class TranscriptionNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $transcriptionId)
    {
        parent::__construct("Transcription {$transcriptionId} was not found.");
    }
}
