<?php

namespace App\Infrastructure\Queue;

use App\Domain\Transcriber\Contracts\TranscriptionExportDispatcherInterface;
use App\Jobs\GeneratePdfExport;
use App\Jobs\GenerateTxtExport;

final class LaravelTranscriptionExportDispatcher implements TranscriptionExportDispatcherInterface
{
    public function dispatch(string $transcriptionId): void
    {
        GenerateTxtExport::dispatch($transcriptionId);
        GeneratePdfExport::dispatch($transcriptionId);
    }
}
