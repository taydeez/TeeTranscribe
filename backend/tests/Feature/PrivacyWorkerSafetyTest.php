<?php

use App\Domain\Privacy\Services\PrivacyService;
use App\Infrastructure\AI\Transcriber\TranscriptionCompletion;
use App\Infrastructure\Exports\TranscriptionExportGenerator;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Jobs\GenerateTranscriptionExports;
use App\Jobs\SubmitTranscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Storage::fake('r2');
});

test('deleted projects acknowledge late callbacks and queued work without recreating text or files', function () {
    $user = User::factory()->create();
    $record = Transcription::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
    app(PrivacyService::class)->delete($user->id, 'transcription', $record->id, 'project');
    app()->call([new SubmitTranscription($record->id, 'en'), 'handle']);
    (new GenerateTranscriptionExports($record->id))->handle(app(OutboxService::class));
    app(TranscriptionCompletion::class)->complete($record->id, 'deepgram', 'late-callback', 'Should not be saved', []);
    $this->postJson(URL::temporarySignedRoute('deepgram.callback', now()->addHour(), ['transcription' => $record->id], absolute: false), [])->assertNoContent();
    expect(Transcription::find($record->id))->toBeNull()
        ->and(OutboxEvent::where('event_type', 'TranscriptionCompleted')->count())->toBe(0);
    expect(Storage::disk('r2')->allFiles())->toBe([]);
    Http::assertNothingSent();
});

test('queued export writers honor a deleted format while still finishing other downloads', function () {
    $user = User::factory()->create();
    $record = Transcription::factory()->create(['user_id' => $user->id, 'status' => 'processing', 'transcript' => 'Keep text']);
    app(PrivacyService::class)->delete($user->id, 'transcription', $record->id, 'generated', 'pdf');
    app(TranscriptionExportGenerator::class)->generate($record->id, 'pdf', 0);
    (new GenerateTranscriptionExports($record->id))->handle(app(OutboxService::class));
    expect($record->refresh()->status)->toBe('complete')
        ->and($record->exports()->where('format', 'pdf')->exists())->toBeFalse()
        ->and($record->exports()->where('format', 'txt')->first()->status)->toBe('completed');
    expect(collect(Storage::disk('r2')->allFiles())->filter(fn ($path) => str_ends_with($path, '.pdf')))->toBeEmpty();
});
