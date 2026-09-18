<?php

use App\Domain\Transcriber\Contracts\TranscriptionMapperInterface as DomainMapperInterface;
use App\Domain\Transcriber\Entities\Transcription as DomainTranscription;
use App\Infrastructure\Persistence\Eloquent\Contracts\TranscriptionMapperInterface;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('round trips guest ownership through the domain and writable attributes', function () {
    $model = Transcription::factory()->forGuestSession()->create();
    $mapper = app(TranscriptionMapperInterface::class);

    $entity = $mapper->toDomain($model);
    $attributes = $mapper->toPersistence($entity);

    expect($entity->id)->toBe($model->id);
    expect($entity->userId)->toBeNull();
    expect($attributes)->toBe([
        'user_id' => null,
        'guest_session_id' => $model->guest_session_id,
        'audio_path' => $model->audio_path, 'file_name' => $model->file_name, 'name' => $model->name, 'folder_name' => $model->folder_name,
        'duration' => null,
        'status' => 'pending',
        'provider_request_id' => null,
        'transcript' => null,
    ]);
    expect($model->guestSession->transcriptions->modelKeys())->toBe([$model->id]);
});

test('maps persisted Eloquent records into independent domain entities', function () {
    $this->freezeTime();
    $model = Transcription::factory()->create([
        'status' => 'completed', 'provider_request_id' => 'request-42', 'transcript' => 'Sample transcript.', 'duration' => 123.456,
    ]);
    $mapper = app(TranscriptionMapperInterface::class);

    $entity = $mapper->toDomain($model);
    $model->transcript = 'Changed after mapping.';

    expect($entity)->toBeInstanceOf(DomainTranscription::class);
    expect(app(DomainMapperInterface::class)->toArray($entity))->toBe([
        'id' => $model->id,
        'user_id' => $model->user_id,
        'guest_session_id' => null,
        'audio_path' => $model->audio_path, 'file_name' => $model->file_name, 'name' => $model->name, 'folder_name' => $model->folder_name,
        'duration' => 123.456,
        'status' => 'completed',
        'provider_request_id' => 'request-42',
        'transcript' => 'Sample transcript.',
        'created_at' => $model->created_at->format('Y-m-d\\TH:i:s.uP'),
        'updated_at' => $model->updated_at->format('Y-m-d\\TH:i:s.uP'),
    ]);
});

test('maps only writable attributes and retains explicit null values', function () {
    $entity = new DomainTranscription(
        id: '01arz3ndektsv4rrffq69g5fav', userId: 7, audioPath: 'audio/sample.mp3', fileName: 'sample.mp3', name: 'sample', status: 'pending',
        createdAt: new DateTimeImmutable('2026-09-17T12:00:00Z'),
        updatedAt: new DateTimeImmutable('2026-09-17T12:00:00Z'),
    );

    $attributes = app(TranscriptionMapperInterface::class)->toPersistence($entity);

    expect($attributes)->toBe([
        'user_id' => 7,
        'guest_session_id' => null,
        'audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null,
        'duration' => null,
        'status' => 'pending',
        'provider_request_id' => null,
        'transcript' => null,
    ]);
});

test('rejects mapping an Eloquent model without an identity', function () {
    app(TranscriptionMapperInterface::class)->toDomain(new Transcription);
})->throws(InvalidArgumentException::class);
