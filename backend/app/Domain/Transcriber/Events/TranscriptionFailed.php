<?php

namespace App\Domain\Transcriber\Events;

final readonly class TranscriptionFailed
{
    public function __construct(public string $transcriptionId) {}
}
