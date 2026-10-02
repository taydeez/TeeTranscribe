<?php

use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Jobs\GenerateTranscriptionExports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('the coordinator uploads both formats before acknowledging the outbox event', function () {
    Storage::fake('r2');
    $transcription = Transcription::factory()->create([
        'name' => 'Customer Interview',
        'status' => 'processing',
        'transcript' => 'The completed transcript.',
    ]);
    $event = app(OutboxService::class)->record(
        "transcription:{$transcription->id}:completed",
        'TranscriptionCompleted',
        $transcription->id,
        ['transcription_id' => $transcription->id],
    );

    (new GenerateTranscriptionExports($transcription->id, $event->id))
        ->handle(app(OutboxService::class));

    $this->assertDatabaseHas('transcription_exports', [
        'transcription_id' => $transcription->id,
        'format' => 'txt',
        'status' => 'completed',
    ]);
    $this->assertDatabaseHas('transcription_exports', [
        'transcription_id' => $transcription->id,
        'format' => 'pdf',
        'status' => 'completed',
    ]);
    Storage::disk('r2')->assertExists("exports/{$transcription->id}/Customer Interview.txt");
    Storage::disk('r2')->assertExists("exports/{$transcription->id}/Customer Interview.pdf");
    expect($transcription->refresh()->status)->toBe('complete')
        ->and($event->refresh()->published_at)->not->toBeNull();
});

test('publishing queues one coordinator without acknowledging the event early', function () {
    Queue::fake();
    $transcription = Transcription::factory()->create([
        'status' => 'processing',
        'transcript' => 'Ready to export.',
    ]);
    $event = OutboxEvent::factory()->create([
        'aggregate_id' => $transcription->id,
        'event_type' => 'TranscriptionCompleted',
        'attempts' => 0,
        'published_at' => null,
    ]);

    $this->artisan('outbox:publish')->assertSuccessful();

    expect($event->refresh()->attempts)->toBe(1)
        ->and($event->published_at)->toBeNull();
    Queue::assertPushed(GenerateTranscriptionExports::class, fn ($job): bool => $job->transcriptionId === $transcription->id
        && $job->outboxEventId === $event->id);
});

test('publishing recovers a prematurely acknowledged incomplete export', function () {
    Queue::fake();
    $transcription = Transcription::factory()->create([
        'status' => 'processing',
        'transcript' => 'Ready to export.',
    ]);
    $event = OutboxEvent::factory()->create([
        'aggregate_id' => $transcription->id,
        'event_type' => 'TranscriptionCompleted',
        'attempts' => 1,
        'published_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $this->artisan('outbox:publish')->assertSuccessful();

    expect($event->refresh()->attempts)->toBe(2)
        ->and($event->published_at)->toBeNull();
    Queue::assertPushed(GenerateTranscriptionExports::class);
});
