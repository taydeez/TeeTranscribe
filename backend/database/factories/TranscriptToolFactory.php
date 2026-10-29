<?php

namespace Database\Factories;

use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptTool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TranscriptTool>
 */
class TranscriptToolFactory extends Factory
{
    protected $model = TranscriptTool::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transcription_id' => Transcription::factory()->state(['status' => 'complete', 'transcript' => 'Hello world.', 'segments' => []]),
            'user_id' => fn (array $attributes): int => Transcription::findOrFail($attributes['transcription_id'])->user_id,
            'operation' => 'summary', 'status' => 'pending', 'source_text' => 'Hello world.', 'source_segments' => [],
            'source_hash' => hash('sha256', json_encode(['Hello world.', []], JSON_THROW_ON_ERROR)),
            'provider' => 'openai', 'model' => 'gpt-4.1-mini',
        ];
    }
}
