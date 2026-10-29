<?php

namespace App\Infrastructure\AI\Translation;

use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Transcriber\Services\TranscriptionExportFileName;
use App\Domain\Transcriber\Services\TranscriptionExportOptions;
use App\Domain\Translation\Contracts\TranslationExportStorageInterface;
use App\Domain\Translation\Entities\Translation;
use App\Infrastructure\Exports\DocxTranscriptionRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class R2TranslationExportStorage implements TranslationExportStorageInterface
{
    public function generate(Translation $translation): array
    {
        $exports = [];
        $disk = Storage::disk('r2');
        foreach (TranscriptionExportOptions::required($translation->segments) as $option) {
            $format = $option['format'];
            if (app(PrivacyCoordinatorInterface::class)->generatedDeleted('translation', $translation->id, $format)) {
                continue;
            }
            $text = $option['variant'] === 'speakers' ? TranscriptionExportOptions::speakerText($translation->segments) : $translation->translatedText;
            $bytes = match ($format) {
                'txt' => $text,
                'pdf' => Pdf::loadView('exports.transcription', ['title' => $translation->name, 'transcript' => $text])->output(),
                'docx' => app(DocxTranscriptionRenderer::class)->render($translation->name, $text),
            };
            $contentType = match ($format) {
                'txt' => 'text/plain; charset=utf-8', 'pdf' => 'application/pdf',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            };
            $path = "translations/{$translation->id}/revisions/{$translation->exportRevision}/{$option['variant']}/".TranscriptionExportFileName::make($translation->name, $format);
            if (! $disk->put($path, $bytes, ['ContentType' => $contentType]) || ! $disk->exists($path) || $disk->size($path) !== strlen($bytes)) {
                throw new RuntimeException('The translation export upload could not be verified.');
            }
            $exports[] = $option + ['status' => 'completed', 'storage_path' => $path];
        }

        return $exports;
    }

    public function downloadUrl(array $export, string $name): ?string
    {
        if (($export['status'] ?? '') !== 'completed' || empty($export['storage_path'])) {
            return null;
        }
        try {
            return Storage::disk('r2')->temporaryUrl($export['storage_path'], now()->addMinutes(15), [
                'ResponseContentDisposition' => 'attachment; filename="'.TranscriptionExportFileName::make($name.($export['variant'] === 'speakers' ? ' - speakers' : ''), $export['format']).'"',
            ]);
        } catch (\Throwable) {
            return null;
        }
    }
}
