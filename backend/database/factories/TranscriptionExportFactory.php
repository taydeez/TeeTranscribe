<?php

namespace Database\Factories;

use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TranscriptionExport> */
class TranscriptionExportFactory extends Factory
{
    protected $model = TranscriptionExport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'transcription_id' => Transcription::factory(),
            'format' => 'txt',
            'status' => 'pending',
            'storage_path' => null,
            'failure_reason' => null,
            'processing_started_at' => null,
        ];
    }
}
