<?php

namespace App\Domain\Transcriber\Contracts;

interface TranscriptionExportDispatcherInterface
{
    public function dispatch(string $transcriptionId): void;
}
