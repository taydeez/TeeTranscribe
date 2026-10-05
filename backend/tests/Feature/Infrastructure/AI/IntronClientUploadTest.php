<?php

use App\Infrastructure\AI\Transcriber\Intron\IntronClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config()->set('transcriber.intron.key', 'test-api-key');
    config()->set('transcriber.intron.endpoint', 'https://intron.example');
    config()->set('transcriber.intron.diarization', true);
});

test('it submits the documented Intron multipart fields', function (): void {
    Http::fake([
        'audio.example.com/*' => Http::response('audio-bytes', 200, ['Content-Type' => 'audio/wav']),
        'intron.example/*' => Http::response(['data' => ['file_id' => 'file-123']]),
    ]);

    $fileId = app(IntronClient::class)->upload(
        'https://audio.example.com/recording.wav',
        'recording.wav',
        'en-NG',
        25,
    );

    expect($fileId)->toBe('file-123');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://audio.example.com/recording.wav');

    Http::assertSent(function (Request $request): bool {
        $parts = collect($request->data())->keyBy('name');
        $audio = $parts->get('audio_file_blob');

        return $request->method() === 'POST'
            && $request->url() === 'https://intron.example/file/v1/upload'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $parts->get('audio_file_name')['contents'] === 'recording.wav'
            && $parts->get('use_language_asr_input')['contents'] === 'en'
            && $parts->get('use_diarization')['contents'] === 'FALSE'
            && $audio['filename'] === 'recording.wav'
            && $audio['headers']['Content-Type'] === 'audio/wav';
    });
});

test('it enables diarization only when the audio meets Intron minimum duration', function (): void {
    Http::fake([
        'audio.example.com/*' => Http::response('audio-bytes', 200, ['Content-Type' => 'audio/wav']),
        'intron.example/*' => Http::response(['data' => ['file_id' => 'file-123']]),
    ]);

    app(IntronClient::class)->upload(
        'https://audio.example.com/recording.wav',
        'recording.wav',
        'en-NG',
        7200,
    );

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST') {
            return false;
        }

        return collect($request->data())
            ->firstWhere('name', 'use_diarization')['contents'] === 'TRUE';
    });
});

test('it streams an R2 object without downloading its signed URL', function (): void {
    Storage::fake('r2');
    Storage::disk('r2')->put('audio/01M40K8ZE4TGFDWD49FCJ6PRH0.wav', 'audio-bytes');
    Http::fake([
        'intron.example/*' => Http::response(['data' => ['file_id' => 'file-123']]),
    ]);

    $fileId = app(IntronClient::class)->upload(
        'https://expired.example.com/recording.wav',
        'recording.wav',
        'en-NG',
        25,
        'audio/01M40K8ZE4TGFDWD49FCJ6PRH0.wav',
    );

    expect($fileId)->toBe('file-123');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
});

test('it rejects upload responses without a file id', function (): void {
    Http::fake([
        'audio.example.com/*' => Http::response('audio-bytes', 200),
        'intron.example/*' => Http::response(['data' => []]),
    ]);

    expect(fn () => app(IntronClient::class)->upload(
        'https://audio.example.com/recording.wav',
        'recording.wav',
        'pcm',
    ))->toThrow(RuntimeException::class, 'file ID');
});
