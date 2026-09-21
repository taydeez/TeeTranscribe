<?php

namespace App\Infrastructure\Storage;

use App\Domain\Transcriber\Contracts\TranscriptionExportUrlGeneratorInterface;
use App\Domain\Transcriber\Entities\TranscriptionExport;
use App\Domain\Transcriber\Services\TranscriptionExportFileName;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class R2TranscriptionExportUrlGenerator implements TranscriptionExportUrlGeneratorInterface
{
    public function generate(TranscriptionExport $export, string $transcriptionName): ?string
    {
        if ($export->status !== 'completed' || $export->storagePath === null) {
            return null;
        }

        try {
            return Storage::disk('r2')->temporaryUrl(
                $export->storagePath,
                now()->addMinutes(15),
                ['ResponseContentDisposition' => 'attachment; filename="'.TranscriptionExportFileName::make($transcriptionName, $export->format).'"'],
            );
        } catch (Throwable) {
            return null;
        }
    }
}
