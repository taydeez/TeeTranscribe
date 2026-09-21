<?php

use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function deepgramCompletionPayload(string $requestId, string $transcript = 'Hello world.'): array
{
    return [
        'metadata' => ['request_id' => $requestId, 'duration' => 12.345],
        'results' => ['channels' => [['alternatives' => [['transcript' => $transcript]]]]],
    ];
}

test('moves the matching transcription to processing and records its completion event', function () {
    $record = Transcription::factory()->create(['provider_request_id' => 'provider-123', 'status' => 'pending']);
    $url = URL::signedRoute('deepgram.callback', ['transcription' => $record->id], absolute: false);

    $this->postJson($url, deepgramCompletionPayload('provider-123'))->assertNoContent();

    $this->assertDatabaseHas('transcriptions', [
        'id' => $record->id, 'status' => 'processing', 'transcript' => 'Hello world.', 'duration' => 12.345,
    ]);
    $event = OutboxEvent::query()->sole();
    expect(Str::isUlid($event->id))->toBeTrue();
    expect($event->event_key)->toBe("transcription:{$record->id}:completed");
    expect($event->event_type)->toBe('TranscriptionCompleted');
    expect($event->aggregate_id)->toBe($record->id);
    expect($event->payload)->toBe(['transcription_id' => $record->id]);
    expect($event->attempts)->toBe(0);
    expect($event->published_at)->toBeNull();
});

test('acknowledges duplicate callbacks without overwriting the transcript or emitting another event', function () {
    $this->freezeTime();
    $record = Transcription::factory()->create(['provider_request_id' => 'provider-123']);
    $url = URL::signedRoute('deepgram.callback', ['transcription' => $record->id], absolute: false);
    $this->postJson($url, deepgramCompletionPayload('provider-123'))->assertNoContent();
    $updatedAt = $record->refresh()->updated_at;
    $event = OutboxEvent::query()->sole();
    app(OutboxService::class)->markPublished($event->id);
    $this->travel(1)->minute();

    $this->postJson($url, deepgramCompletionPayload('provider-123', 'Duplicate changed text'))->assertNoContent();

    expect($record->refresh()->transcript)->toBe('Hello world.');
    expect($record->updated_at)->toEqual($updatedAt);
    $this->assertDatabaseCount('outbox_events', 1);
    expect($event->refresh()->published_at)->not->toBeNull();
});

test('rejects a provider request ID belonging to a different transcription', function () {
    $record = Transcription::factory()->create(['provider_request_id' => 'provider-123']);
    $other = Transcription::factory()->create(['provider_request_id' => 'provider-other']);
    $url = URL::signedRoute('deepgram.callback', ['transcription' => $record->id], absolute: false);

    $this->postJson($url, deepgramCompletionPayload('provider-other'))->assertNotFound();

    $this->assertDatabaseHas('transcriptions', ['id' => $record->id, 'status' => 'pending', 'transcript' => null]);
    $this->assertDatabaseHas('transcriptions', ['id' => $other->id, 'status' => 'pending', 'transcript' => null]);
    $this->assertDatabaseCount('outbox_events', 0);
});

test('rejects callbacks for an unknown provider request ID', function () {
    $record = Transcription::factory()->create(['provider_request_id' => null]);
    $url = URL::signedRoute('deepgram.callback', ['transcription' => $record->id], absolute: false);

    $this->postJson($url, deepgramCompletionPayload('unknown'))->assertNotFound();

    $this->assertDatabaseHas('transcriptions', ['id' => $record->id, 'status' => 'pending', 'transcript' => null]);
    $this->assertDatabaseCount('outbox_events', 0);
});

test('marks the transcription failed and rolls back the event if recording the outbox event fails', function () {
    $record = Transcription::factory()->create(['provider_request_id' => 'provider-123', 'duration' => 5]);
    $url = URL::signedRoute('deepgram.callback', ['transcription' => $record->id], absolute: false);
    $this->mock(OutboxService::class, function ($mock) {
        $mock->shouldReceive('record')->once()->andReturnUsing(function (...$arguments) {
            (new OutboxService)->record(...$arguments);
            throw new RuntimeException('Outbox failure');
        });
    });

    $this->postJson($url, deepgramCompletionPayload('provider-123'))->assertInternalServerError();

    $this->assertDatabaseHas('transcriptions', [
        'id' => $record->id, 'status' => 'failed', 'transcript' => null, 'duration' => 5,
    ]);
    $this->assertDatabaseCount('outbox_events', 0);
});

test('accepts a completed recording with no speech and preserves duration when omitted', function () {
    $record = Transcription::factory()->create(['provider_request_id' => 'provider-123', 'duration' => 5]);
    $url = URL::signedRoute('deepgram.callback', ['transcription' => $record->id], absolute: false);
    $payload = deepgramCompletionPayload('provider-123', '');
    unset($payload['metadata']['duration']);

    $this->postJson($url, $payload)->assertNoContent();

    $this->assertDatabaseHas('transcriptions', [
        'id' => $record->id, 'status' => 'processing', 'transcript' => '', 'duration' => 5,
    ]);
    $this->assertDatabaseCount('outbox_events', 1);
});

test('rejects unsigned callbacks without changing persistence', function () {
    $record = Transcription::factory()->create(['provider_request_id' => 'provider-123']);

    $this->postJson(route('deepgram.callback', ['transcription' => $record->id]), deepgramCompletionPayload('provider-123'))
        ->assertForbidden();

    $this->assertDatabaseHas('transcriptions', ['id' => $record->id, 'status' => 'pending', 'transcript' => null]);
    $this->assertDatabaseCount('outbox_events', 0);
});
