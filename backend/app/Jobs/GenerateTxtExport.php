<?php

namespace App\Jobs;

use App\Domain\Transcriber\Services\FinalizeTranscriptionExports;
use App\Domain\Transcriber\Services\TranscriptionExportFileName;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateTxtExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 120;

    public function __construct(
        public string $transcriptionId
    ) {
        $this->onQueue('exports');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                "transcription:{$this->transcriptionId}:txt"
            ))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public function handle(): void
    {
        $transcription = Transcription::query()
            ->findOrFail($this->transcriptionId);
        $export = null;

        try {
            if ($transcription->status === 'complete') {
                return;
            }

            if (! in_array($transcription->status, ['processing', 'failed'], true)) {
                throw new RuntimeException('Transcription is not ready for export.');
            }

            if ($transcription->status === 'failed') {
                $transcription->update(['status' => 'processing']);
            }

            if (! is_string($transcription->transcript)) {
                throw new RuntimeException('Transcription text is unavailable.');
            }

            $export = TranscriptionExport::firstOrCreate(
                ['transcription_id' => $transcription->id, 'format' => 'txt'],
                ['status' => 'pending'],
            );

            if ($export->status === 'completed') {
                app(FinalizeTranscriptionExports::class)->handle($transcription->id);

                return;
            }

            $path = "exports/{$transcription->id}/".TranscriptionExportFileName::make($transcription->name, 'txt');
            $export->update([
                'status' => 'pending',
                'processing_started_at' => now(),
                'failure_reason' => null,
            ]);
            $disk = Storage::disk('r2');

            $uploaded = $disk->put(
                $path,
                $transcription->transcript,
                ['ContentType' => 'text/plain; charset=utf-8'],
            );

            if (! $uploaded) {
                throw new RuntimeException('Failed to upload TXT export to R2.');
            }

            if (! $disk->exists($path)) {
                throw new RuntimeException('TXT export upload could not be verified.');
            }

            $export->update([
                'status' => 'completed',
                'storage_path' => $path,
                'failure_reason' => null,
            ]);
            app(FinalizeTranscriptionExports::class)->handle($transcription->id);
        } catch (Throwable $exception) {
            $export?->update([
                'status' => 'failed',
                'failure_reason' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            $transcription->update(['status' => 'failed']);

            throw $exception;
        }
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function failed(?Throwable $exception): void
    {
        app(TranscriptionOutcomePublisher::class)->failed($this->transcriptionId);
    }
}
