<?php

use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Services\TranscribeService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => Queue::fake());

test('stores verified audio metadata through the transcription service', function () {
    Http::fake(['*' => Http::response(['request_id' => 'provider-123'])]);
    $url = 'https://audio.example.com/interview.mp3';

    $record = app(TranscribeService::class)->startNewTranscription([
        'audio_url' => $url, 'language_code' => 'en', 'duration' => 123.456,
        'file_name' => 'interview.mp3', 'name' => 'Customer interview', 'folder_name' => 'Research',
    ]);
    expect($record->status)->toBe('pending');

    $this->assertDatabaseHas('transcriptions', [
        'audio_path' => $url, 'duration' => 123.456,
        'file_name' => 'interview.mp3', 'name' => 'Customer interview', 'folder_name' => 'Research',
        'provider' => 'deepgram', 'provider_request_id' => null,
    ]);
});

test('derives names for clients that only send an audio URL', function () {
    Http::fake(['*' => Http::response(['request_id' => 'provider-123'])]);

    $transcription = app(TranscribeService::class)->startNewTranscription([
        'audio_url' => 'https://audio.example.com/audio/Team%20meeting.mp3?signature=example',
        'language_code' => 'en',
    ]);

    expect($transcription->fileName)->toBe('Team meeting');
    expect($transcription->name)->toBe('Team meeting');
    expect($transcription->folderName)->toBeNull();
    $this->assertDatabaseHas('transcriptions', [
        'id' => $transcription->id, 'file_name' => 'Team meeting', 'name' => 'Team meeting',
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
