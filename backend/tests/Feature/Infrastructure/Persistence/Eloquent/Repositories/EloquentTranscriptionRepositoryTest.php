<?php

use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Entities\Transcription as DomainTranscription;
use App\Domain\Transcriber\Exceptions\TranscriptionNotFoundException;
use App\Infrastructure\Persistence\Eloquent\Models\GuestSession;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

test('persists reads updates and clears audio duration in seconds', function () {
    $repository = app(TranscriptionRepositoryInterface::class);
    $created = $repository->create(['audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null, 'duration' => 123.456]);

    expect($created->duration)->toBe(123.456);
    expect($repository->find($created->id)?->duration)->toBe(123.456);
    expect($repository->all()[0]->duration)->toBe(123.456);
    expect($repository->update($created->id, ['status' => 'complete'])->duration)->toBe(123.456);
    expect($repository->update($created->id, ['duration' => 0])->duration)->toBe(0.0);
    $this->assertDatabaseHas('transcriptions', ['id' => $created->id, 'duration' => 0]);
    expect($repository->update($created->id, ['duration' => null])->duration)->toBeNull();
    $this->assertDatabaseHas('transcriptions', ['id' => $created->id, 'duration' => null]);
});

test('generates a ULID and defaults to pending with no owner', function () {
    $repository = app(TranscriptionRepositoryInterface::class);

    $entity = $repository->create(['audio_path' => 'audio/unowned.mp3', 'file_name' => 'unowned.mp3', 'name' => 'unowned', 'folder_name' => null]);

    expect(Str::isUlid($entity->id))->toBeTrue();
    expect($entity->userId)->toBeNull();
    expect($entity->guestSessionId)->toBeNull();
    expect($entity->status)->toBe('pending');
    $this->assertDatabaseHas('transcriptions', [
        'id' => $entity->id, 'audio_path' => 'audio/unowned.mp3', 'file_name' => 'unowned.mp3', 'name' => 'unowned', 'folder_name' => null,
        'user_id' => null, 'guest_session_id' => null, 'status' => 'pending',
    ]);
});

test('creates and reads guest transcriptions and can transfer ownership to a user', function () {
    $guest = GuestSession::factory()->create();
    $user = User::factory()->create();
    $repository = app(TranscriptionRepositoryInterface::class);

    $created = $repository->create(['audio_path' => 'audio/guest.mp3', 'file_name' => 'guest.mp3', 'name' => 'guest', 'folder_name' => null, 'guest_session_id' => $guest->id]);

    expect($created->guestSessionId)->toBe($guest->id);
    expect($created->userId)->toBeNull();
    expect($repository->find($created->id)?->guestSessionId)->toBe($guest->id);
    expect($repository->all()[0]->guestSessionId)->toBe($guest->id);

    $updated = $repository->update($created->id, ['user_id' => $user->id, 'guest_session_id' => null]);

    expect($updated->userId)->toBe($user->id);
    expect($updated->guestSessionId)->toBeNull();
    $this->assertDatabaseHas('transcriptions', [
        'id' => $created->id, 'user_id' => $user->id, 'guest_session_id' => null,
    ]);
});

test('retains a transcription and clears its user when the user is deleted', function () {
    $user = User::factory()->create();
    $transcription = Transcription::factory()->for($user)->create();

    $user->delete();

    $entity = app(TranscriptionRepositoryInterface::class)->find($transcription->id);
    expect($entity)->toBeInstanceOf(DomainTranscription::class);
    expect($entity->userId)->toBeNull();
    $this->assertDatabaseHas('transcriptions', ['id' => $transcription->id, 'user_id' => null]);
});

test('retains a transcription and clears its guest session when the session is deleted', function () {
    $guest = GuestSession::factory()->create();
    $transcription = Transcription::factory()->forGuestSession($guest)->create();

    $guest->delete();

    $entity = app(TranscriptionRepositoryInterface::class)->find($transcription->id);
    expect($entity)->toBeInstanceOf(DomainTranscription::class);
    expect($entity->guestSessionId)->toBeNull();
    $this->assertDatabaseHas('transcriptions', ['id' => $transcription->id, 'guest_session_id' => null]);
});

test('creates a transcription with nullable provider and transcript fields', function () {
    $user = User::factory()->create();
    $data = ['user_id' => $user->id, 'audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null, 'status' => 'pending'];

    $transcription = app(TranscriptionRepositoryInterface::class)->create($data);

    expect($transcription)->toBeInstanceOf(DomainTranscription::class);
    $this->assertDatabaseHas('transcriptions', [
        ...$data, 'id' => $transcription->id, 'provider_request_id' => null, 'transcript' => null,
    ]);
});

test('finds a transcription by id and returns null for a missing id', function () {
    $transcription = Transcription::factory()->create();
    $repository = app(TranscriptionRepositoryInterface::class);

    expect($repository->find($transcription->id)?->id)->toBe($transcription->id);
    expect($repository->find('01arz3ndektsv4rrffq69g5fav'))->toBeNull();
});

test('lists transcriptions newest id first', function () {
    $older = Transcription::factory()->create(['id' => '01arz3ndektsv4rrffq69g5fav']);
    $newer = Transcription::factory()->create(['id' => '01arz3ndektsv4rrffq69g5faw']);

    $records = app(TranscriptionRepositoryInterface::class)->all();

    expect(array_map(fn (DomainTranscription $record): string => $record->id, $records))
        ->toBe([$newer->id, $older->id]);
});

test('updates selected fields and preserves the remaining transcription data', function () {
    $transcription = Transcription::factory()->create();
    $data = ['status' => 'complete', 'transcript' => 'Hello world.', 'provider_request_id' => 'request-123'];

    $updated = app(TranscriptionRepositoryInterface::class)->update($transcription->id, $data);

    expect($updated->transcript)->toBe('Hello world.');
    $this->assertDatabaseHas('transcriptions', [
        ...$data, 'id' => $updated->id, 'audio_path' => $transcription->audio_path, 'user_id' => $transcription->user_id,
    ]);
});

test('deletes only the requested transcription', function () {
    $transcription = Transcription::factory()->create();
    $other = Transcription::factory()->create();

    $deleted = app(TranscriptionRepositoryInterface::class)->delete($transcription->id);

    expect($deleted)->toBeTrue();
    $this->assertModelMissing($transcription);
    $this->assertModelExists($other);
});

test('rejects updating a missing transcription', function () {
    app(TranscriptionRepositoryInterface::class)->update('01arz3ndektsv4rrffq69g5fav', ['status' => 'complete']);
})->throws(TranscriptionNotFoundException::class);

test('rejects deleting a missing transcription', function () {
    app(TranscriptionRepositoryInterface::class)->delete('01arz3ndektsv4rrffq69g5fav');
})->throws(TranscriptionNotFoundException::class);

test('clears nullable fields without changing omitted fields', function () {
    $transcription = Transcription::factory()->create([
        'status' => 'complete', 'provider_request_id' => 'request-42', 'transcript' => 'Old text.',
    ]);

    $updated = app(TranscriptionRepositoryInterface::class)->update($transcription->id, [
        'provider_request_id' => null, 'transcript' => null,
    ]);

    expect($updated->providerRequestId)->toBeNull();
    expect($updated->transcript)->toBeNull();
    $this->assertDatabaseHas('transcriptions', [
        'id' => $transcription->id, 'status' => 'complete',
        'provider_request_id' => null, 'transcript' => null,
    ]);
});

test('returns an empty list when there are no transcriptions', function () {
    expect(app(TranscriptionRepositoryInterface::class)->all())->toBe([]);
});

test('does not return an entity when creation is cancelled', function () {
    $user = User::factory()->create();
    $event = 'eloquent.creating: '.Transcription::class;
    Event::listen($event, fn (): bool => false);

    try {
        expect(fn () => app(TranscriptionRepositoryInterface::class)->create([
            'user_id' => $user->id, 'audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null, 'status' => 'pending',
        ]))->toThrow(RuntimeException::class, 'The transcription could not be created.');
        $this->assertDatabaseCount('transcriptions', 0);
    } finally {
        Event::forget($event);
    }
});

test('does not return unsaved changes when an update is cancelled', function () {
    $transcription = Transcription::factory()->create();
    $event = 'eloquent.updating: '.Transcription::class;
    Event::listen($event, fn (): bool => false);

    try {
        expect(fn () => app(TranscriptionRepositoryInterface::class)->update(
            $transcription->id, ['status' => 'complete'],
        ))->toThrow(RuntimeException::class, 'The transcription could not be updated.');
        $this->assertDatabaseHas('transcriptions', ['id' => $transcription->id, 'status' => 'pending']);
    } finally {
        Event::forget($event);
    }
});
