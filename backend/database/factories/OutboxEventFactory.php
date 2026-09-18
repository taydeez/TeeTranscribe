<?php

namespace Database\Factories;

use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OutboxEvent> */
class OutboxEventFactory extends Factory
{
    protected $model = OutboxEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_key' => (string) Str::ulid(),
            'event_type' => 'transcription.created',
            'aggregate_id' => (string) Str::ulid(),
            'payload' => ['status' => 'pending'],
            'published_at' => null,
            'attempts' => 0,
        ];
    }
}
