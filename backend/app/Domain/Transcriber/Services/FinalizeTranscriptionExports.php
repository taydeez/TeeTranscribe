<?php

namespace App\Domain\Transcriber\Services;

use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Support\Facades\DB;

class FinalizeTranscriptionExports
{
    public function handle(string $transcriptionId): void
    {
        DB::transaction(function () use ($transcriptionId): void {
            $transcription = Transcription::query()->lockForUpdate()->findOrFail($transcriptionId);
            $exports = $transcription->exports()->lockForUpdate()->get()->keyBy('format');

            if ($exports->contains(fn ($export): bool => $export->status === 'failed')) {
                $transcription->update(['status' => 'failed']);

                return;
            }

            if ($exports->has('txt') && $exports->has('pdf')
                && $exports['txt']->status === 'completed' && filled($exports['txt']->storage_path)
                && $exports['pdf']->status === 'completed' && filled($exports['pdf']->storage_path)) {
                $transcription->update(['status' => 'complete']);
            }
        });
    }
}
