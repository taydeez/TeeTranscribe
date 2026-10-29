<?php

namespace App\Domain\Transcriber\Events;

final readonly class TranscriptionCompleted
{
    public function __construct(public string $transcriptionId) {}
}
