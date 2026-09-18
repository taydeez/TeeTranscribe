<?php

namespace App\Domain\Transcriber\Exceptions;

use RuntimeException;

class TranscriptionExportNotFoundException extends RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct("Transcription export [{$id}] was not found.");
    }
}
