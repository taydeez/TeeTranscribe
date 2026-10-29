<?php

use App\Http\Requests\Transcription\CreateTranscriptionRequest;
use App\Infrastructure\Persistence\Eloquent\Models\GuestSession;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Route::post('/_test/transcriptions', fn (CreateTranscriptionRequest $request) => $request->validated());
});

test('accepts only an audio path and excludes unrelated input', function () {
    $this->postJson('/_test/transcriptions', ['audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null, 'unrelated' => 'ignored'])
        ->assertOk()
        ->assertExactJson(['audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null]);
});

test('accepts nullable and non-negative audio durations in seconds', function (?float $duration) {
    $data = ['audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null, 'duration' => $duration];

    $this->postJson('/_test/transcriptions', $data)->assertOk()->assertExactJson($data);
})->with([null, 0.0, 123.456]);

test('rejects invalid audio durations', function (mixed $duration) {
    $this->postJson('/_test/transcriptions', ['audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null, 'duration' => $duration])
        ->assertUnprocessable()->assertJsonValidationErrors('duration');
})->with([-1, 'invalid', 1000000000]);

test('accepts nullable ownership and optional fields', function () {
    $data = [
        'audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null, 'user_id' => null, 'guest_session_id' => null,
        'provider_request_id' => null, 'transcript' => null,
    ];

    $this->postJson('/_test/transcriptions', $data)->assertOk()->assertExactJson($data);
});

test('accepts an existing user and populated transcription fields', function () {
    $user = User::factory()->create();
    $data = [
        'user_id' => $user->id, 'audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null,
        'status' => 'complete', 'provider_request_id' => 'request-123', 'transcript' => 'Hello world.',
    ];

    $this->postJson('/_test/transcriptions', $data)->assertOk()->assertExactJson($data);
});

test('accepts an existing guest session without a user', function () {
    $guest = GuestSession::factory()->create();
    $data = ['audio_path' => 'audio/guest.mp3', 'file_name' => 'guest.mp3', 'name' => 'guest', 'folder_name' => null, 'guest_session_id' => $guest->id];

    $this->postJson('/_test/transcriptions', $data)->assertOk()->assertExactJson($data);
});

test('returns validation errors for invalid transcription fields', function (array $input, string $field) {
    $this->postJson('/_test/transcriptions', array_replace(['audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null], $input))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing audio path' => [['audio_path' => null], 'audio_path'],
    'non-string audio path' => [['audio_path' => []], 'audio_path'],
    'long audio path' => [['audio_path' => str_repeat('a', 256)], 'audio_path'],
    'non-integer user' => [['user_id' => 'invalid'], 'user_id'],
    'unknown user' => [['user_id' => 999], 'user_id'],
    'malformed guest UUID' => [['guest_session_id' => 'invalid'], 'guest_session_id'],
    'unknown guest session' => [['guest_session_id' => '01994fab-4658-7b00-a001-123456789abc'], 'guest_session_id'],
    'non-string provider id' => [['provider_request_id' => []], 'provider_request_id'],
    'long provider id' => [['provider_request_id' => str_repeat('a', 256)], 'provider_request_id'],
    'null status' => [['status' => null], 'status'],
    'non-string status' => [['status' => []], 'status'],
    'long status' => [['status' => str_repeat('a', 256)], 'status'],
    'non-string transcript' => [['transcript' => []], 'transcript'],
    'client-generated id' => [['id' => '01arz3ndektsv4rrffq69g5fav'], 'id'],
    'client-created timestamp' => [['created_at' => '2026-09-17T12:00:00Z'], 'created_at'],
    'client-updated timestamp' => [['updated_at' => '2026-09-17T12:00:00Z'], 'updated_at'],
]);

test('rejects duplicate provider request ids', function () {
    Transcription::factory()->create(['provider_request_id' => 'request-123']);

    $this->postJson('/_test/transcriptions', ['audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null, 'provider_request_id' => 'request-123'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('provider_request_id');
});

test('allows multiple transcriptions without provider request ids', function () {
    Transcription::factory()->create(['provider_request_id' => null]);
    $data = ['audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null, 'provider_request_id' => null];

    $this->postJson('/_test/transcriptions', $data)->assertOk()->assertExactJson($data);
});
