<?php

use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use App\Jobs\GeneratePdfExport;
use App\Jobs\GenerateTxtExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

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
    Queue::assertPushed(GenerateTxtExport::class, fn ($job): bool => $job->transcriptionId === $transcription->id);
    Queue::assertPushed(GeneratePdfExport::class, fn ($job): bool => $job->transcriptionId === $transcription->id);
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
