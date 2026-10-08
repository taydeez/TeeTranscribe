<?php

namespace App\Domain\Transcriber\Entities;

final readonly class TranscriptionEditResult
{
    public function __construct(public Transcription $transcription, public bool $changed) {}
}
