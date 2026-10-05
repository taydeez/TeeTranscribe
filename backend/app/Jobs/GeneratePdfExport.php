<?php

namespace App\Jobs;

use App\Domain\Transcriber\Services\FinalizeTranscriptionExports;
use App\Domain\Transcriber\Services\TranscriptionExportFileName;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GeneratePdfExport implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $tries = 10;

    public int $timeout = 120;

    public function __construct(public string $transcriptionId)
    {
        $this->onQueue('exports');
    }

    public function failed(?Throwable $exception): void
    {
        app(TranscriptionOutcomePublisher::class)->failed($this->transcriptionId);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $transcription = Transcription::query()->findOrFail($this->transcriptionId);
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
                ['transcription_id' => $transcription->id, 'format' => 'pdf'],
                ['status' => 'pending'],
            );
            if ($export->status === 'completed') {
                app(FinalizeTranscriptionExports::class)->handle($transcription->id);

                return;
            }
            $path = "exports/{$transcription->id}/".TranscriptionExportFileName::make($transcription->name, 'pdf');
            $export->update(['status' => 'pending', 'processing_started_at' => now(), 'failure_reason' => null]);
            $disk = Storage::disk('r2');
            $pdf = Pdf::loadView('exports.transcription', [
                'title' => $transcription->name,
                'transcript' => $transcription->transcript ?? '',
            ])->output();

            if (! $disk->put($path, $pdf, ['ContentType' => 'application/pdf'])) {
                throw new RuntimeException('Failed to upload PDF export to R2.');
            }
            if (! $disk->exists($path)) {
                throw new RuntimeException('PDF export upload could not be verified.');
            }
            $export->update(['status' => 'completed', 'storage_path' => $path, 'failure_reason' => null]);
            app(FinalizeTranscriptionExports::class)->handle($transcription->id);
        } catch (Throwable $exception) {
            $export?->update(['status' => 'failed', 'failure_reason' => mb_substr($exception->getMessage(), 0, 1000)]);
            $transcription->update(['status' => 'failed']);
            throw $exception;
        }
    }
}
