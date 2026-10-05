<?php

use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;
use App\Domain\Transcriber\Contracts\TranscriptionPollingDispatcherInterface;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Services\TranscribeService;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use App\Jobs\GeneratePdfExport;
use App\Jobs\GenerateTxtExport;
use App\Jobs\PollIntronTranscription;
use App\Jobs\SubmitTranscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('derives a filename without its extension from a submitted audio url', function () {
    Queue::fake();

    $transcription = app(TranscribeService::class)->startNewTranscription([
        'audio_url' => 'https://fghjk.example/vghjkl/hjjhjhbgj.mp3?signature=private',
        'language_code' => 'en',
    ]);

    expect($transcription->fileName)->toBe('hjjhjhbgj')
        ->and($transcription->name)->toBe('hjjhjhbgj')
        ->and($transcription->status)->toBe('pending')
        ->and($transcription->providerRequestId)->toBeNull();
    Queue::assertPushed(SubmitTranscription::class, fn (SubmitTranscription $job): bool => $job->transcriptionId === $transcription->id);
});

test('marks a pending transcription as failed when provider submission exhausts its retries', function () {
    $transcription = Transcription::factory()->create(['status' => 'pending']);

    (new SubmitTranscription($transcription->id, 'en'))->failed(new RuntimeException('Provider unavailable'));

    expect($transcription->refresh()->status)->toBe('failed');
});

test('persists the Intron file id before dispatching its polling job', function () {
    config()->set('transcriber.intron.languages', ['en-NG', 'pcm', 'yo', 'ig', 'ha']);
    config()->set('transcriber.intron.key', 'test-api-key');
    config()->set('transcriber.intron.endpoint', 'https://intron.example');
    Queue::fake();
    Http::fake([
        'audio.example.com/*' => Http::response('audio-bytes', 200, ['Content-Type' => 'audio/wav']),
        'intron.example/*' => Http::response(['data' => ['file_id' => 'intron-file-123']]),
    ]);

    $transcription = app(TranscribeService::class)->startNewTranscription([
        'audio_url' => 'https://audio.example.com/interview.wav',
        'language_code' => 'en-NG',
    ]);

    expect($transcription->provider)->toBe('intron')
        ->and($transcription->providerRequestId)->toBeNull();
    Queue::assertPushed(SubmitTranscription::class);

    app(SubmitTranscription::class, [
        'transcriptionId' => $transcription->id,
        'languageCode' => 'en-NG',
    ])->handle(
        app(TranscriberGatewayResolverInterface::class),
        app(TranscriptionRepositoryInterface::class),
        app(TranscriptionPollingDispatcherInterface::class),
    );

    Queue::assertPushed(PollIntronTranscription::class, function (PollIntronTranscription $job) use ($transcription): bool {
        return $job->transcriptionId === $transcription->id
            && Transcription::query()->findOrFail($transcription->id)->provider_request_id === 'intron-file-123';
    });
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

test('regenerated exports replace files containing the previous transcript', function () {
    Storage::fake('r2');
    $transcription = Transcription::factory()->create([
        'name' => 'Edited Interview',
        'status' => 'processing',
        'transcript' => 'The corrected transcript.',
    ]);
    $txtPath = "exports/{$transcription->id}/Edited Interview.txt";
    $pdfPath = "exports/{$transcription->id}/Edited Interview.pdf";
    Storage::disk('r2')->put($txtPath, 'The old transcript.');
    Storage::disk('r2')->put($pdfPath, 'old pdf');

    (new GenerateTxtExport($transcription->id))->handle();
    (new GeneratePdfExport($transcription->id))->handle();

    expect(Storage::disk('r2')->get($txtPath))->toBe('The corrected transcript.')
        ->and(Storage::disk('r2')->get($pdfPath))->not->toBe('old pdf')
        ->and($transcription->refresh()->status)->toBe('complete');
});
