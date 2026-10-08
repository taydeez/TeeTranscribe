<?php

namespace App\Jobs;

use App\Infrastructure\Exports\TranscriptionExportGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class GeneratePdfExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 120;

    public function __construct(public string $transcriptionId, public int $revision = 0)
    {
        $this->onQueue('exports');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('transcription:'.$this->transcriptionId.':pdf'))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(): void
    {
        app(TranscriptionExportGenerator::class)->generate($this->transcriptionId, 'pdf', $this->revision);
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function failed(?\Throwable $exception): void
    {
        app(TranscriptionExportGenerator::class)->failed($this->transcriptionId, $this->revision);
    }
}
