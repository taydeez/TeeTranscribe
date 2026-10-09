<?php

namespace App\Infrastructure\Exports;

use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Transcriber\Services\FinalizeTranscriptionExports;
use App\Domain\Transcriber\Services\TranscriptionExportFileName;
use App\Domain\Transcriber\Services\TranscriptionExportOptions;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class TranscriptionExportGenerator
{
    public function generate(string $id, string $format, int $revision, string $variant = 'plain'): void
    {
        app(PrivacyCoordinatorInterface::class)->exclusive('transcription', $id, function () use ($id, $format, $revision, $variant): void {
            $privacy = app(PrivacyCoordinatorInterface::class);
            if (! $privacy->projectDeleted('transcription', $id) && ! $privacy->generatedDeleted('transcription', $id, $format)) {
                $this->render($id, $format, $revision, $variant);
            }
        });
    }

    private function render(string $id, string $format, int $revision, string $variant): void
    {
        if (! in_array($format, TranscriptionExportOptions::FORMATS, true) || ! in_array($variant, ['plain', 'speakers'], true)) {
            throw new RuntimeException('Unsupported export format.');
        }
        $snapshot = DB::transaction(function () use ($id, $format, $revision, $variant): ?Transcription {
            $record = Transcription::query()->lockForUpdate()->findOrFail($id);
            if ($record->export_revision !== $revision || $record->status === 'complete') {
                return null;
            }
            if (! in_array($record->status, ['processing', 'failed'], true) || ! is_string($record->transcript)) {
                throw new RuntimeException('Transcription is not ready for export.');
            }
            if ($variant === 'speakers' && ! TranscriptionExportOptions::hasSpeakers($record->segments ?? [])) {
                return null;
            }
            $export = TranscriptionExport::firstOrCreate(['transcription_id' => $id, 'format' => $format, 'variant' => $variant], ['status' => 'pending', 'export_revision' => $revision]);
            if ($export->status === 'completed' && $export->export_revision === $revision) {
                return null;
            }
            $record->update(['status' => 'processing']);
            $export->update(['status' => 'pending', 'export_revision' => $revision, 'processing_started_at' => now(), 'failure_reason' => null]);

            return $record;
        });
        if ($snapshot === null) {
            app(FinalizeTranscriptionExports::class)->handle($id);

            return;
        }

        $prefix = $revision === 0 ? "exports/{$id}/" : "exports/{$id}/revisions/{$revision}/";
        $path = $prefix.($variant === 'speakers' ? 'speakers/' : '').TranscriptionExportFileName::make($snapshot->name, $format);
        try {
            $text = $variant === 'speakers' ? TranscriptionExportOptions::speakerText($snapshot->segments ?? []) : $snapshot->transcript;
            $bytes = match ($format) {
                'txt' => $text,
                'pdf' => Pdf::loadView('exports.transcription', ['title' => $snapshot->name, 'transcript' => $text])->output(),
                'docx' => app(DocxTranscriptionRenderer::class)->render($snapshot->name, $text),
            };
            $contentType = match ($format) {
                'txt' => 'text/plain; charset=utf-8',
                'pdf' => 'application/pdf',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            };
            $disk = Storage::disk('r2');
            if (! $disk->put($path, $bytes, ['ContentType' => $contentType])) {
                throw new RuntimeException('Failed to upload '.strtoupper($format).' export to R2.');
            }
            if (! $disk->exists($path) || $disk->size($path) !== strlen($bytes)) {
                throw new RuntimeException(strtoupper($format).' export upload could not be verified.');
            }
            DB::transaction(function () use ($id, $format, $revision, $path, $variant): void {
                $record = Transcription::query()->lockForUpdate()->find($id);
                if ($record === null || $record->export_revision !== $revision) {
                    return;
                }
                $record->exports()->where('format', $format)->where('variant', $variant)->where('export_revision', $revision)
                    ->update(['status' => 'completed', 'storage_path' => $path, 'failure_reason' => null]);
            });
            app(FinalizeTranscriptionExports::class)->handle($id);
        } catch (Throwable $exception) {
            $current = DB::transaction(function () use ($id, $format, $revision, $exception, $variant): bool {
                $record = Transcription::query()->lockForUpdate()->find($id);
                if ($record === null || $record->export_revision !== $revision) {
                    return false;
                }
                $record->exports()->where('format', $format)->where('variant', $variant)->where('export_revision', $revision)
                    ->update(['status' => 'failed', 'failure_reason' => mb_substr($exception->getMessage(), 0, 1000)]);
                $record->update(['status' => 'failed']);

                return true;
            });
            if ($current) {
                throw $exception;
            }
        }
    }

    public function failed(string $id, int $revision): void
    {
        DB::transaction(function () use ($id, $revision): void {
            $record = Transcription::query()->lockForUpdate()->find($id);
            if ($record !== null && $record->export_revision === $revision) {
                app(TranscriptionOutcomePublisher::class)->failed($id);
            }
        });
    }
}
