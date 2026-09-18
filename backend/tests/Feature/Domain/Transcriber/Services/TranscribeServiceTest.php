<?php

use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Services\TranscribeService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

test('stores the submitted audio metadata through the transcription endpoint', function () {
    Http::preventStrayRequests();
    $url = 'https://audio.example.com/interview.mp3';

    $response = $this->postJson('/api/v1/transcribe', [
        'audio_url' => $url, 'language_code' => 'en', 'duration' => 123.456,
        'file_name' => 'interview.mp3', 'name' => 'Customer interview', 'folder_name' => 'Research',
    ])->assertOk()->assertJsonPath('fileName', 'interview.mp3')
        ->assertJsonPath('name', 'Customer interview')->assertJsonPath('folderName', 'Research');

    $this->assertDatabaseHas('transcriptions', [
        'id' => $response->json('id'), 'audio_path' => $url, 'duration' => 123.456,
        'file_name' => 'interview.mp3', 'name' => 'Customer interview', 'folder_name' => 'Research',
    ]);
});

test('derives names for clients that only send an audio URL', function () {
    $transcription = app(TranscribeService::class)->storeTranscription([
        'audio_url' => 'https://audio.example.com/audio/Team%20meeting.mp3?signature=example',
        'language_code' => 'en',
    ]);

    expect($transcription->fileName)->toBe('Team meeting.mp3');
    expect($transcription->name)->toBe('Team meeting');
    expect($transcription->folderName)->toBeNull();
    $this->assertDatabaseHas('transcriptions', [
        'id' => $transcription->id, 'file_name' => 'Team meeting.mp3', 'name' => 'Team meeting',
    ]);
});

test('reads renames and clears the transcription folder without losing the original file name', function () {
    $repository = app(TranscriptionRepositoryInterface::class);
    $created = $repository->create([
        'audio_path' => 'audio/interview.mp3', 'file_name' => 'interview.mp3',
        'name' => 'Interview', 'folder_name' => 'Research',
    ]);

    $updated = $repository->update($created->id, ['name' => 'Edited title', 'folder_name' => null]);

    expect($updated->name)->toBe('Edited title');
    expect($updated->fileName)->toBe('interview.mp3');
    expect($updated->folderName)->toBeNull();
    expect($repository->find($created->id))->toEqual($updated);
    expect($repository->all())->toEqual([$updated]);
    $this->assertDatabaseHas('transcriptions', [
        'id' => $created->id, 'name' => 'Edited title', 'file_name' => 'interview.mp3', 'folder_name' => null,
    ]);
});
