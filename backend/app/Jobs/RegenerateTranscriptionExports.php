<?php

namespace App\Jobs;

use App\Domain\Transcriber\Services\TranscriptionExportOptions;
use App\Infrastructure\Exports\TranscriptionExportGenerator;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RegenerateTranscriptionExports implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public ?int $revision = null;

    public function __construct(public string $transcriptionId)
    {
        $this->revision = Transcription::find($transcriptionId)?->export_revision;
        $this->onConnection('redis');
        $this->onQueue('exports');
    }

    public function uniqueId(): string
    {
        return $this->transcriptionId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('regenerate:'.$this->transcriptionId))->releaseAfter(5)->expireAfter(360)];
    }

    public function handle(TranscriptionExportGenerator $generator): void
    {
        $record = Transcription::findOrFail($this->transcriptionId);
        $this->revision = $record->export_revision;
        foreach (TranscriptionExportOptions::required($record->segments ?? []) as $option) {
            $generator->generate($this->transcriptionId, $option['format'], $this->revision, $option['variant']);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if ($this->revision !== null) {
            app(TranscriptionExportGenerator::class)->failed($this->transcriptionId, $this->revision);
        }
    }
}
