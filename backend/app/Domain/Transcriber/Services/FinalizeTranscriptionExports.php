<?php

namespace App\Domain\Transcriber\Services;

use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Transcriber\Contracts\TranscriptionOutcomePublisherInterface;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Support\Facades\DB;

class FinalizeTranscriptionExports
{
    public function handle(string $transcriptionId): void
    {
        DB::transaction(function () use ($transcriptionId): void {
            $transcription = Transcription::query()->lockForUpdate()->find($transcriptionId);
            if ($transcription === null || $transcription->status === 'complete') {
                return;
            }
            $exports = $transcription->exports()->where('export_revision', $transcription->export_revision)->lockForUpdate()->get()->keyBy(fn ($export) => $export->format.':'.$export->variant);

            if ($exports->contains(fn ($export): bool => $export->status === 'failed')) {
                $transcription->update(['status' => 'failed']);

                return;
            }

            foreach (TranscriptionExportOptions::required($transcription->segments ?? []) as $option) {
                if (app(PrivacyCoordinatorInterface::class)->generatedDeleted('transcription', $transcriptionId, $option['format'])) {
                    continue;
                }
                $export = $exports->get($option['format'].':'.$option['variant']);
                if ($export === null || $export->status !== 'completed' || blank($export->storage_path)) {
                    return;
                }
            }
            $transcription->update(['status' => 'complete']);
            app(TranscriptionOutcomePublisherInterface::class)->completed($transcription->id);
        });
    }
}
