<?php

namespace App\Infrastructure\Queue;

use App\Domain\Transcriber\Contracts\TranscriptionExportDispatcherInterface;
use App\Jobs\RegenerateTranscriptionExports;

final class LaravelTranscriptionExportDispatcher implements TranscriptionExportDispatcherInterface
{
    public function dispatch(string $transcriptionId): void
    {
        RegenerateTranscriptionExports::dispatch($transcriptionId)->delay(5)->afterCommit();
    }
}
