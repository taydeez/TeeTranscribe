<?php

use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use App\Jobs\RegenerateTranscriptionExports;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('timed edits preserve provider timestamps and regenerate the combined text', function () {
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $record = Transcription::factory()->create([
        'user_id' => $user->id, 'provider' => 'deepgram', 'status' => 'complete',
        'segments' => [['start' => 1, 'end' => 4, 'speaker' => 'Speaker 1', 'text' => 'Original.', 'confidence' => 0.9]],
    ]);
    $this->patchJson("/api/v1/transcriptions/{$record->id}", [
        'transcript' => 'Ignored combined text',
        'segments' => [['text' => 'Corrected.', 'speaker' => 'Ada', 'start' => 999]],
    ])->assertOk()->assertJsonPath('transcript', 'Corrected.');
    expect($record->refresh()->segments[0])->toMatchArray([
        'start' => 1, 'end' => 4, 'speaker' => 'Ada', 'text' => 'Corrected.', 'confidence' => 0.9,
    ]);
    Queue::assertPushed(RegenerateTranscriptionExports::class);

});

test('an owner can edit a transcript and regenerate its exports', function () {
    Queue::fake();
    $user = User::factory()->create();
    $transcription = Transcription::factory()->create([
        'user_id' => $user->id,
        'status' => 'complete',
        'transcript' => 'Original transcript.',
    ]);
    TranscriptionExport::factory()->create([
        'transcription_id' => $transcription->id,
        'format' => 'txt',
        'status' => 'completed',
        'storage_path' => "exports/{$transcription->id}/Recording.txt",
        'processing_started_at' => now(),
    ]);
    TranscriptionExport::factory()->create([
        'transcription_id' => $transcription->id,
        'format' => 'pdf',
        'status' => 'failed',
        'failure_reason' => 'Old failure',
        'processing_started_at' => now(),
    ]);
    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/transcriptions/{$transcription->id}", [
        'transcript' => 'Corrected transcript.',
    ])->assertOk()
        ->assertJsonPath('transcript', 'Corrected transcript.')
        ->assertJsonPath('status', 'processing');

    $this->assertDatabaseHas('transcriptions', [
        'id' => $transcription->id,
        'transcript' => 'Corrected transcript.',
        'status' => 'processing',
    ]);
    expect($transcription->exports()->pluck('status')->all())->toBe(['pending', 'pending'])
        ->and($transcription->exports()->pluck('failure_reason')->filter()->all())->toBe([])
        ->and($transcription->exports()->pluck('processing_started_at')->filter()->all())->toBe([]);
    Queue::assertPushed(RegenerateTranscriptionExports::class, fn ($job): bool => $job->transcriptionId === $transcription->id);

});

test('a user cannot edit another users transcript', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $transcription = Transcription::factory()->create([
        'user_id' => $owner->id,
        'transcript' => 'Private transcript.',
        'status' => 'complete',
    ]);
    Sanctum::actingAs($otherUser);

    $this->patchJson("/api/v1/transcriptions/{$transcription->id}", [
        'transcript' => 'Unauthorized edit.',
    ])->assertNotFound();

    expect($transcription->refresh()->transcript)->toBe('Private transcript.')
        ->and($transcription->status)->toBe('complete');
    Queue::assertNothingPushed();
});

test('editing a transcript requires authentication', function () {
    $transcription = Transcription::factory()->create();

    $this->patchJson("/api/v1/transcriptions/{$transcription->id}", [
        'transcript' => 'Edited transcript.',
    ])->assertUnauthorized();
});

test('unchanged saves leave completed exports untouched and enqueue nothing', function () {
    Queue::fake();
    $record = Transcription::factory()->create(['status' => 'complete', 'transcript' => 'Same transcript.', 'segments' => []]);
    Sanctum::actingAs($record->user);
    $export = TranscriptionExport::factory()->create(['transcription_id' => $record->id, 'format' => 'txt', 'status' => 'completed', 'storage_path' => 'exports/original.txt']);
    $this->patchJson("/api/v1/transcriptions/{$record->id}", ['transcript' => 'Same transcript.'])->assertOk()->assertJsonPath('status', 'complete');
    expect($record->refresh()->export_revision)->toBe(0)
        ->and($export->refresh()->status)->toBe('completed')
        ->and($export->storage_path)->toBe('exports/original.txt');
    Queue::assertNothingPushed();
});

test('rapid changed saves share one delayed regeneration and excess requests are rate limited', function () {
    Queue::fake();
    $record = Transcription::factory()->create(['status' => 'complete', 'transcript' => 'Original.', 'segments' => []]);
    Sanctum::actingAs($record->user);
    for ($i = 1; $i <= 10; $i++) {
        $this->patchJson("/api/v1/transcriptions/{$record->id}", ['transcript' => "Edit {$i}."])->assertOk();
    }
    $this->patchJson("/api/v1/transcriptions/{$record->id}", ['transcript' => 'Abusive edit.'])->assertStatus(429)->assertHeader('Retry-After');
    expect($record->refresh()->transcript)->toBe('Edit 10.')
        ->and($record->export_revision)->toBe(10);
    Queue::assertPushed(RegenerateTranscriptionExports::class, 1);
    Queue::assertPushed(RegenerateTranscriptionExports::class, fn ($job) => $job->delay === 5);
});
