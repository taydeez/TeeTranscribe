<?php

use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

test('records an event once without overwriting its original payload on retry', function () {
    $service = app(OutboxService::class);
    $aggregate = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $payload = ['transcript' => 'Hello', 'metadata' => ['duration' => 12.5]];

    $event = $service->record('created:'.$aggregate, 'transcription.created', $aggregate, $payload);
    $retry = $service->record('created:'.$aggregate, 'transcription.created', $aggregate, ['transcript' => 'Changed']);

    expect(Str::isUlid($event->id))->toBeTrue();
    expect($retry->id)->toBe($event->id);
    expect($retry->payload)->toBe($payload);
    expect($retry->attempts)->toBe(0);
    expect($retry->published_at)->toBeNull();
    $this->assertDatabaseCount('outbox_events', 1);
    $this->assertDatabaseHas('outbox_events', [
        'id' => $event->id, 'event_key' => 'created:'.$aggregate, 'aggregate_id' => $aggregate,
        'event_type' => 'transcription.created', 'published_at' => null, 'attempts' => 0,
    ]);
});

test('returns the oldest unpublished events up to the requested limit', function () {
    $this->freezeTime();
    OutboxEvent::factory()->create(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAA', 'published_at' => now()]);
    $oldest = OutboxEvent::factory()->create(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAB']);
    OutboxEvent::factory()->create(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAC']);

    expect(app(OutboxService::class)->pending(1)->modelKeys())->toBe([$oldest->id]);
});

test('counts retries and preserves publication time on repeated acknowledgement', function () {
    $this->freezeTime();
    $event = OutboxEvent::factory()->create();
    $service = app(OutboxService::class);
    $service->incrementAttempts($event->id);
    $second = $service->incrementAttempts($event->id);
    expect($second->attempts)->toBe(2);

    $published = $service->markPublished($event->id);
    $publishedAt = $published->published_at;
    $this->travel(1)->minute();
    $repeated = $service->markPublished($event->id);
    $service->incrementAttempts($event->id);

    expect($repeated->published_at)->toEqual($publishedAt);
    expect($event->refresh()->attempts)->toBe(2);
    expect($service->pending())->toHaveCount(0);
    $this->assertDatabaseHas('outbox_events', ['id' => $event->id, 'attempts' => 2]);
});

test('rolls back the event together with its transcription', function () {
    expect(fn () => DB::transaction(function () {
        $transcription = Transcription::factory()->create();
        app(OutboxService::class)->record(
            'created:'.$transcription->id, 'transcription.created', $transcription->id, ['status' => 'pending'],
        );

        throw new RuntimeException('Abort transaction');
    }))->toThrow(RuntimeException::class, 'Abort transaction');

    $this->assertDatabaseCount('outbox_events', 0);
    $this->assertDatabaseCount('transcriptions', 0);
});

test('rejects an invalid pending event limit', function () {
    expect(fn () => app(OutboxService::class)->pending(0))->toThrow(InvalidArgumentException::class);
});

test('does not silently acknowledge or retry missing events', function (string $operation) {
    expect(fn () => app(OutboxService::class)->{$operation}('01ARZ3NDEKTSV4RRFFQ69G5FAV'))
        ->toThrow(ModelNotFoundException::class);
})->with(['markPublished', 'incrementAttempts']);
