<?php

namespace App\Jobs;

use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateTranscriptionExports implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $transcriptionId
    ) {}

    public function handle(): void
    {
        $transcription = Transcription::findOrFail(
            $this->transcriptionId
        );

        if ($transcription->status !== 'processing') {
            return;
        }

        GenerateTxtExport::dispatch($transcription->id)
            ->onQueue('exports');

        GeneratePdfExport::dispatch($transcription->id)->onQueue('exports');
    }
}
