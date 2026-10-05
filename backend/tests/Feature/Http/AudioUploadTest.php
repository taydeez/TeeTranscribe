<?php

use App\Domain\Transcriber\Entities\Transcription;
use App\Domain\Transcriber\Services\TranscribeService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disks.r2' => [
        'driver' => 's3', 'key' => 'test-access-key', 'secret' => 'test-secret-key',
        'region' => 'auto', 'bucket' => 'audio-test',
        'endpoint' => 'https://test-account.r2.cloudflarestorage.com',
        'use_path_style_endpoint' => true,
        'request_checksum_calculation' => 'when_required',
    ]]);
    Storage::forgetDisk('r2');
});

test('signs upload and read URLs for the same audio object without uploading bytes', function () {
    $response = $this->postJson('/api/v1/uploads/presign', [
        'filename' => 'voice-note.mp3', 'content_type' => 'audio/mpeg', 'size' => 2048,
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $upload = $response->json('upload_url');
    $read = $response->json('audio_url');
    expect(parse_url($upload, PHP_URL_HOST))->toBe('test-account.r2.cloudflarestorage.com');
    expect(parse_url($upload, PHP_URL_PATH))->toMatch('/^\/audio-test\/audio\/[0-9A-Z]{26}\.mp3$/');
    expect(parse_url($read, PHP_URL_PATH))->toBe(parse_url($upload, PHP_URL_PATH));
    parse_str(parse_url($upload, PHP_URL_QUERY), $query);
    expect((int) $query['X-Amz-Expires'])->toBeGreaterThan(0)->toBeLessThanOrEqual(1200);
    expect($query['X-Amz-Signature'])->not->toBeEmpty();
    expect($response->json('headers.Content-Type'))->toBe('audio/mpeg');
    expect($response->json('audio_storage_path'))->toMatch('/^audio\/[0-9A-Z]{26}\.mp3$/');
    expect($response->json('headers'))->not->toHaveKeys(['Host', 'Content-Length']);
    expect($response->getContent())->not->toContain('test-secret-key');
});

test('rejects invalid audio upload metadata', function (array $data, string $field) {
    $this->postJson('/api/v1/uploads/presign', array_replace([
        'filename' => 'sample.mp3', 'content_type' => 'audio/mpeg', 'size' => 2048,
    ], $data))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unsupported file' => [['filename' => 'sample.exe'], 'filename'],
    'unsupported type' => [['content_type' => 'text/html'], 'content_type'],
    'empty file' => [['size' => 0], 'size'],
    'oversized file' => [['size' => 104857601], 'size'],
]);

test('reports unavailable storage when credentials are missing', function () {
    config(['filesystems.disks.r2.key' => null]);

    $this->postJson('/api/v1/uploads/presign', [
        'filename' => 'sample.mp3', 'content_type' => 'audio/mpeg', 'size' => 2048,
    ])->assertServiceUnavailable();
});

test('passes the uploaded audio metadata to the transcription service', function () {
    $url = 'https://test-account.r2.cloudflarestorage.com/audio-test/audio/sample.mp3?signature=example';
    $this->mock(TranscribeService::class, function ($mock) use ($url) {
        $mock->shouldReceive('startNewTranscription')->once()->with(['audio_url' => $url, 'language_code' => 'en'])
            ->andReturn(new Transcription(
                id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', userId: null, audioPath: $url,
                fileName: 'sample.mp3', name: 'sample',
            ));
    });

    $response = $this->postJson('/api/v1/transcribe', ['audio_url' => $url, 'language_code' => 'en'])->assertAccepted();

    expect($response->json())->toBe([
        'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'status' => 'pending',
        'message' => 'File queued for transcription.',
    ]);
});

test('rejects malformed transcription input before calling the provider', function () {
    $this->mock(TranscribeService::class, function ($mock) {
        $mock->shouldNotReceive('startNewTranscription');
    });

    $this->postJson('/api/v1/transcribe', ['audio_url' => 'not-a-url', 'language_code' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors(['audio_url', 'language_code']);
});
