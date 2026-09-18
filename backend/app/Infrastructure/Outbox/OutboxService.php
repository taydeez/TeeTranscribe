<?php

namespace App\Infrastructure\Outbox;

use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class OutboxService
{
    /**
     * Record inside the caller's database transaction to keep the event and its aggregate atomic.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(string $eventKey, string $eventType, string $aggregateId, array $payload): OutboxEvent
    {
        return OutboxEvent::query()->firstOrCreate(
            ['event_key' => $eventKey],
            ['event_type' => $eventType, 'aggregate_id' => $aggregateId, 'payload' => $payload],
        )->refresh();
    }

    /** @return Collection<int, OutboxEvent> */
    public function pending(int $limit = 100): Collection
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The pending event limit must be positive.');
        }

        return OutboxEvent::query()->whereNull('published_at')->orderBy('id')->limit($limit)->get();
    }

    public function incrementAttempts(string $id): OutboxEvent
    {
        $event = OutboxEvent::query()->findOrFail($id);
        OutboxEvent::query()->whereKey($id)->whereNull('published_at')->increment('attempts');

        return $event->refresh();
    }

    public function markPublished(string $id): OutboxEvent
    {
        $event = OutboxEvent::query()->findOrFail($id);
        OutboxEvent::query()->whereKey($id)->whereNull('published_at')->update(['published_at' => now()]);

        return $event->refresh();
    }
}
