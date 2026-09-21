<?php

use App\Domain\Transcriber\Services\TranscribeService;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use App\Jobs\GeneratePdfExport;
use App\Jobs\GenerateTxtExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('derives a filename without its extension from a submitted audio url', function () {
    Http::fake(['*' => Http::response(['request_id' => 'provider-123'])]);

    $transcription = app(TranscribeService::class)->startNewTranscription([
        'audio_url' => 'https://fghjk.example/vghjkl/hjjhjhbgj.mp3?signature=private',
        'language_code' => 'en',
    ]);

    expect($transcription->fileName)->toBe('hjjhjhbgj')
        ->and($transcription->name)->toBe('hjjhjhbgj')
        ->and($transcription->status)->toBe('pending')
        ->and($transcription->providerRequestId)->toBe('provider-123');
});

test('marks a transcription as failed when the provider submission fails', function () {
    Http::fake(['*' => Http::response(['message' => 'Provider unavailable'], 500)]);

    expect(fn () => app(TranscribeService::class)->startNewTranscription([
        'audio_url' => 'https://audio.example.com/interview.mp3',
        'language_code' => 'en',
    ]))->toThrow(RuntimeException::class);

    expect(Transcription::query()->sole()->status)->toBe('failed');
});

test('uses the transcription name for both exports and completes after both uploads', function () {
    Storage::fake('r2');
    $transcription = Transcription::factory()->create([
        'name' => 'Odega Interview',
        'status' => 'processing',
        'transcript' => 'The completed interview transcript.',
    ]);

    (new GenerateTxtExport($transcription->id))->handle();
    expect($transcription->refresh()->status)->toBe('processing');

    (new GeneratePdfExport($transcription->id))->handle();

    expect($transcription->refresh()->status)->toBe('complete');
    $this->assertDatabaseHas('transcription_exports', [
        'transcription_id' => $transcription->id,
        'format' => 'txt',
        'status' => 'completed',
        'storage_path' => "exports/{$transcription->id}/Odega Interview.txt",
    ]);
    $this->assertDatabaseHas('transcription_exports', [
        'transcription_id' => $transcription->id,
        'format' => 'pdf',
        'status' => 'completed',
        'storage_path' => "exports/{$transcription->id}/Odega Interview.pdf",
    ]);
    expect(TranscriptionExport::query()->count())->toBe(2);
    Storage::disk('r2')->assertExists("exports/{$transcription->id}/Odega Interview.txt");
    Storage::disk('r2')->assertExists("exports/{$transcription->id}/Odega Interview.pdf");
});
