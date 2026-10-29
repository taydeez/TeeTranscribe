<?php

namespace App\Domain\Folder\Exceptions;

use RuntimeException;

final class TranscriptionCannotBeAddedToFolderException extends RuntimeException
{
    public function __construct(public readonly string $transcriptionId)
    {
        parent::__construct("Transcription {$transcriptionId} does not belong to this user.");
    }
}
