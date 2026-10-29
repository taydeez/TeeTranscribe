<?php

use App\Infrastructure\AI\Transcriber\Intron\IntronClient;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Jobs\PollIntronTranscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function runIntronPoll(PollIntronTranscription $job): void
{
    $job->handle(app(IntronClient::class), app(OutboxService::class));
}

test('it stores a completed Intron transcript and records one outbox event', function (): void {
    $transcription = Transcription::factory()->create([
        'provider' => 'intron',
        'provider_request_id' => 'file-123',
    ]);
    Http::fake([
        '*' => Http::response(['data' => [
            'processing_status' => 'FILE_TRANSCRIBED',
            'audio_transcript' => 'Speaker 1: Hello there.',
            'processed_audio_duration_in_seconds' => 12.5,
        ]]),
    ]);

    runIntronPoll(new PollIntronTranscription($transcription->id));
    runIntronPoll(new PollIntronTranscription($transcription->id));

    expect($transcription->refresh()->status)->toBe('processing')
        ->and($transcription->transcript)->toBe('Speaker 1: Hello there.')
        ->and($transcription->duration)->toBe(12.5)
        ->and(OutboxEvent::query()->count())->toBe(1);
});

test('it marks provider processing failures as terminal without throwing', function (): void {
    $transcription = Transcription::factory()->create([
        'provider' => 'intron',
        'provider_request_id' => 'file-123',
    ]);
    Http::fake(['*' => Http::response(['data' => ['processing_status' => 'FILE_PROCESSING_FAILED']])]);

    runIntronPoll(new PollIntronTranscription($transcription->id));

    expect($transcription->refresh()->status)->toBe('failed')
        ->and(OutboxEvent::query()->count())->toBe(0);
});

test('it does not poll terminal Intron transcriptions', function (): void {
    Http::preventStrayRequests();
    $transcription = Transcription::factory()->create([
        'provider' => 'intron',
        'provider_request_id' => 'file-123',
        'status' => 'failed',
    ]);

    runIntronPoll(new PollIntronTranscription($transcription->id));

    Http::assertNothingSent();
});

test('it rejects completed responses without a usable transcript', function (): void {
    $transcription = Transcription::factory()->create([
        'provider' => 'intron',
        'provider_request_id' => 'file-123',
    ]);
    Http::fake(['*' => Http::response(['data' => [
        'processing_status' => 'FILE_TRANSCRIBED',
        'audio_transcript' => null,
    ]])]);

    expect(fn () => runIntronPoll(new PollIntronTranscription($transcription->id)))
        ->toThrow(RuntimeException::class, 'usable transcript');

    expect($transcription->refresh()->status)->toBe('pending')
        ->and(OutboxEvent::query()->count())->toBe(0);
});

test('it respects the provider retry-after delay', function (): void {
    $transcription = Transcription::factory()->create([
        'provider' => 'intron',
        'provider_request_id' => 'file-123',
    ]);
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '42'])]);
    $job = (new PollIntronTranscription($transcription->id))->withFakeQueueInteractions();

    runIntronPoll($job);

    $job->assertReleased(42);
    expect($transcription->refresh()->status)->toBe('pending');
});

test('it marks a pending transcription failed when polling is exhausted', function (): void {
    $transcription = Transcription::factory()->create([
        'provider' => 'intron',
        'provider_request_id' => 'file-123',
    ]);

    (new PollIntronTranscription($transcription->id))->failed(new RuntimeException('Provider unavailable'));

    expect($transcription->refresh()->status)->toBe('failed');
});
