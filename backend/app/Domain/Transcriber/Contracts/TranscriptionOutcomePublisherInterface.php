<?php

namespace App\Domain\Transcriber\Contracts;

interface TranscriptionOutcomePublisherInterface
{
    public function completed(string $id): void;

    public function failed(string $id, bool $pendingOnly = false): void;
}
