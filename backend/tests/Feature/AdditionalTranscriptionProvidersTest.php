<?php

use App\Domain\Billing\Contracts\MediaDurationInspectorInterface;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Billing\Services\UsageQuoteService;
use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;
use App\Domain\Transcriber\Contracts\TranscriptionPollingDispatcherInterface;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Infrastructure\AI\Transcriber\ElevenLabs\ElevenLabsTranscriptionClient;
use App\Infrastructure\AI\Transcriber\Google\GoogleSpeechAudioStorage;
use App\Infrastructure\AI\Transcriber\Google\GoogleSpeechClient;
use App\Infrastructure\AI\Transcriber\Google\GoogleSpeechCredentials;
use App\Infrastructure\AI\Transcriber\TranscriptionCompletion;
use App\Infrastructure\Billing\ConfigBillingSettings;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Jobs\PollGoogleTranscription;
use App\Jobs\SubmitTranscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function elevenSpeechEvent(Transcription $record): array
{
    return ['type' => 'speech_to_text_transcription', 'data' => [
        'request_id' => 'request-123', 'webhook_metadata' => ['transcription_id' => $record->id],
        'transcription' => ['text' => 'Hello there. Welcome.', 'words' => [
            ['type' => 'word', 'text' => 'Hello', 'start' => 0, 'end' => 0.5, 'speaker_id' => 'speaker_0'],
            ['type' => 'spacing', 'text' => ' ', 'start' => 0.5, 'end' => 0.5, 'speaker_id' => 'speaker_0'],
            ['type' => 'word', 'text' => 'there.', 'start' => 0.5, 'end' => 1, 'speaker_id' => 'speaker_0'],
            ['type' => 'word', 'text' => 'Welcome.', 'start' => 1.5, 'end' => 2, 'speaker_id' => 'speaker_1'],
        ]],
    ]];
}
function sendElevenSpeechEvent($test, array $payload, ?int $timestamp = null, ?string $signature = null)
{
    config()->set('transcriber.elevenlabs.webhook_secret', 'test-webhook-secret');
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp ??= time();
    $signature ??= 't='.$timestamp.',v0='.hash_hmac('sha256', $timestamp.'.'.$body, 'test-webhook-secret');

    return $test->call('POST', '/api/v1/webhooks/elevenlabs/transcription', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ELEVENLABS_SIGNATURE' => $signature,
    ], $body);
}
beforeEach(function () {
    config(['transcriber.google.credentials' => 'test-credentials.json', 'transcriber.elevenlabs.key' => 'test-key', 'transcriber.elevenlabs.webhook_id' => 'webhook-test', 'transcriber.elevenlabs.webhook_secret' => 'webhook-secret', 'transcriber.intron.key' => 'test-key']);
    config()->set('transcriber.google.project', 'test-project');
    config()->set('transcriber.google.bucket', 'test-speech-bucket');
    config()->set('transcriber.google.location', 'us');
    $this->mock(GoogleSpeechCredentials::class)->shouldReceive('token')->andReturn('test-access-token');
    Http::preventStrayRequests();
});

test('configurable overrides and both new fallback providers resolve without replacing Intron', function () {
    config()->set('transcriber.language_providers', ['yo' => 'elevenlabs', 'en' => 'google']);
    $resolver = app(TranscriberGatewayResolverInterface::class);
    expect($resolver->resolve('yo-NG')->provider())->toBe('elevenlabs')
        ->and($resolver->resolve('en-US')->provider())->toBe('google')
        ->and($resolver->resolve('ig')->provider())->toBe('intron');
    foreach (['google', 'elevenlabs'] as $provider) {
        config()->set('transcriber.fallback', $provider);
        expect($resolver->resolve('fr')->provider())->toBe($provider);
    }
});

test('Google enables speakers only for supported languages', function (string $language, bool $speakers, string $locale) {
    Http::fake(['*speech.googleapis.com/*' => Http::response(['name' => 'projects/test-project/locations/us/operations/op-123'])]);
    app(GoogleSpeechClient::class)->transcribe('gs://test-speech-bucket/audio', $language);
    Http::assertSent(fn ($request) => $request['config']['languageCodes'] === [$locale]
        && array_key_exists('diarizationConfig', (array) $request['config']['features']) === $speakers
        && ((array) $request['config']['features'])['enableWordTimeOffsets'] === true);
})->with([['en', true, 'en-US'], ['yo', false, 'yo-NG'], ['ha', false, 'ha-NG'], ['en-AU', false, 'en-AU']]);

test('Google stages verified R2 audio and persists the operation before polling', function () {
    Storage::fake('r2');
    Storage::disk('r2')->put('uploads/audio.wav', 'audio-bytes');
    Queue::fake();
    $record = Transcription::factory()->create(['provider' => 'google', 'audio_storage_path' => 'uploads/audio.wav', 'duration' => 15]);
    $uploaded = null;
    Http::fake([
        'storage.googleapis.com/*' => function ($request) use (&$uploaded) {
            $uploaded = $request->body();

            return Http::response(['name' => 'audio']);
        },
        '*speech.googleapis.com/*' => Http::response(['name' => 'projects/test-project/locations/us/operations/op-123']),
    ]);
    (new SubmitTranscription($record->id, 'en'))->handle(app(TranscriberGatewayResolverInterface::class), app(TranscriptionRepositoryInterface::class), app(TranscriptionPollingDispatcherInterface::class));
    expect($record->refresh()->provider_request_id)->toBe('projects/test-project/locations/us/operations/op-123');
    Queue::assertPushed(PollGoogleTranscription::class);
    expect($uploaded)->toBe('audio-bytes');
});

test('Google results preserve speakers and timestamps and completion is idempotent', function () {
    $record = Transcription::factory()->create(['provider' => 'google', 'provider_request_id' => 'projects/test-project/locations/us/operations/op-123']);
    Http::fake([
        '*speech.googleapis.com/*' => Http::response(['done' => true, 'response' => ['results' => [
            'gs://test-speech-bucket/audio' => ['inlineResult' => ['transcript' => ['results' => [['alternatives' => [[
                'transcript' => 'Hello. Welcome.', 'words' => [
                    ['word' => 'Hello.', 'startOffset' => '0s', 'endOffset' => '0.5s', 'speakerLabel' => '1'],
                    ['word' => 'Welcome.', 'startOffset' => '1s', 'endOffset' => '2s', 'speakerLabel' => '2'],
                ],
            ]]]]]]],
        ]]]),
        'storage.googleapis.com/*' => Http::response([], 204),
    ]);
    $job = new PollGoogleTranscription($record->id);
    $job->handle(app(GoogleSpeechClient::class), app(TranscriptionCompletion::class), app(GoogleSpeechAudioStorage::class));
    $job->handle(app(GoogleSpeechClient::class), app(TranscriptionCompletion::class), app(GoogleSpeechAudioStorage::class));
    expect($record->refresh()->status)->toBe('processing')->and($record->transcript)->toBe('Hello. Welcome.')
        ->and($record->segments[1]['speaker'])->toBe('Speaker 2')->and($record->segments[1]['start'])->toEqual(1)
        ->and(OutboxEvent::count())->toBe(1);
});

test('Google provider failures stop processing and missing timestamps do not invent segments', function () {
    $record = Transcription::factory()->create(['provider' => 'google', 'provider_request_id' => 'projects/test-project/locations/us/operations/op-123']);
    Http::fake(['*speech.googleapis.com/*' => Http::response(['done' => true, 'error' => ['code' => 3]]), 'storage.googleapis.com/*' => Http::response([], 204)]);
    (new PollGoogleTranscription($record->id))->handle(app(GoogleSpeechClient::class), app(TranscriptionCompletion::class), app(GoogleSpeechAudioStorage::class));
    expect($record->refresh()->status)->toBe('failed')->and(OutboxEvent::count())->toBe(0);
});

test('ElevenLabs sends asynchronous speaker transcription requests using its own model and price', function () {
    config()->set('transcriber.elevenlabs.key', 'test-api-key');
    config()->set('transcriber.elevenlabs.webhook_id', 'webhook-123');
    config()->set('transcriber.elevenlabs.webhook_secret', 'test-secret');
    config()->set('billing.rates.transcription.elevenlabs.scribe_v2.credits', '50');
    Http::fake(['api.elevenlabs.io/*' => Http::response(['request_id' => 'request-123'])]);
    expect(app(ElevenLabsTranscriptionClient::class)->transcribe('https://audio.example.com/audio.wav', 'yo', 'transcription-id'))->toBe('request-123');
    Http::assertSent(function ($request) {
        $parts = collect($request->data())->keyBy('name');

        return $request->hasHeader('xi-api-key', 'test-api-key')
            && $parts['model_id']['contents'] === 'scribe_v2' && $parts['diarize']['contents'] === 'true'
            && $parts['webhook']['contents'] === 'true' && $parts['language_code']['contents'] === 'yor';
    });
    $settings = app(ConfigBillingSettings::class);
    expect($settings->model('elevenlabs'))->toBe('scribe_v2')->and($settings->model('google'))->toBe('chirp_3')
        ->and($settings->rate('transcription', 'elevenlabs', 'scribe_v2')['credit_units'])->toBeGreaterThan(0);
});

test('ElevenLabs completion is authenticated and duplicate callbacks create one export event', function () {
    $record = Transcription::factory()->create(['provider' => 'elevenlabs', 'provider_request_id' => 'request-123']);
    sendElevenSpeechEvent($this, elevenSpeechEvent($record))->assertNoContent();
    sendElevenSpeechEvent($this, elevenSpeechEvent($record))->assertNoContent();
    expect($record->refresh()->status)->toBe('processing')->and($record->transcript)->toBe('Hello there. Welcome.')
        ->and($record->segments)->toHaveCount(2)->and($record->segments[0]['text'])->toBe('Hello there.')
        ->and($record->segments[1]['speaker'])->toBe('Speaker 2')->and(OutboxEvent::count())->toBe(1);
});

test('ElevenLabs callback can arrive before submission saves its request identifier', function () {
    $record = Transcription::factory()->create(['provider' => 'elevenlabs', 'provider_request_id' => null]);
    sendElevenSpeechEvent($this, elevenSpeechEvent($record))->assertNoContent();
    expect($record->refresh()->provider_request_id)->toBe('request-123')->and($record->status)->toBe('processing');
});

test('ElevenLabs rejects invalid signatures stale events and mismatched requests', function () {
    $record = Transcription::factory()->create(['provider' => 'elevenlabs', 'provider_request_id' => 'other-request']);
    sendElevenSpeechEvent($this, elevenSpeechEvent($record), signature: 't='.time().',v0=wrong')->assertUnauthorized();
    sendElevenSpeechEvent($this, elevenSpeechEvent($record), time() - 1801)->assertUnauthorized();
    sendElevenSpeechEvent($this, elevenSpeechEvent($record))->assertNotFound();
    expect($record->refresh()->status)->toBe('pending')->and(OutboxEvent::count())->toBe(0);
});

test('both new providers can edit timed transcripts without changing timing', function (string $provider) {
    $record = Transcription::factory()->create(['provider' => $provider, 'status' => 'complete', 'transcript' => 'Hello.',
        'segments' => [['start' => 0, 'end' => 2, 'speaker' => 'Speaker 1', 'text' => 'Hello.', 'confidence' => null]]]);
    app(TranscriptionRepositoryInterface::class)->updateTranscriptForUser($record->id, $record->user_id, 'Corrected.', [['text' => 'Corrected.', 'speaker' => 'Ada']]);
    expect($record->refresh()->segments[0])->toMatchArray(['start' => 0, 'end' => 2, 'text' => 'Corrected.', 'speaker' => 'Ada']);
})->with(['google', 'elevenlabs']);

test('an existing Google submission keeps its quoted provider and model after configuration changes', function () {
    Storage::fake('r2');
    Storage::disk('r2')->put('uploads/audio.wav', 'audio-bytes');
    Queue::fake();
    config()->set('transcriber.fallback', 'elevenlabs');
    config()->set('transcriber.language_providers', ['en' => 'elevenlabs']);
    config()->set('transcriber.google.model', 'new-model');
    $record = Transcription::factory()->create(['provider' => 'google', 'audio_storage_path' => 'uploads/audio.wav', 'duration' => 15]);
    Http::fake(['storage.googleapis.com/*' => Http::response(['name' => 'audio']),
        '*speech.googleapis.com/*' => Http::response(['name' => 'projects/test-project/locations/us/operations/op-123'])]);
    (new SubmitTranscription($record->id, 'en', quotedModel: 'chirp_3'))->handle(app(TranscriberGatewayResolverInterface::class), app(TranscriptionRepositoryInterface::class), app(TranscriptionPollingDispatcherInterface::class));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'speech.googleapis.com') && $request['config']['model'] === 'chirp_3');
    expect($record->refresh()->provider)->toBe('google');
});

test('Google polls again while its operation is running', function () {
    $record = Transcription::factory()->create(['provider' => 'google', 'provider_request_id' => 'projects/test-project/locations/us/operations/op-123']);
    Http::fake(['*speech.googleapis.com/*' => Http::response(['done' => false])]);
    $job = (new PollGoogleTranscription($record->id))->withFakeQueueInteractions();
    $job->handle(app(GoogleSpeechClient::class), app(TranscriptionCompletion::class), app(GoogleSpeechAudioStorage::class));
    $job->assertReleased(15);
    expect($record->refresh()->status)->toBe('pending')->and(OutboxEvent::count())->toBe(0);
});

test('Google words without speaker labels remain timed but do not create fictional speakers', function () {
    $record = Transcription::factory()->create(['provider' => 'google', 'provider_request_id' => 'projects/test-project/locations/us/operations/op-123']);
    Http::fake(['*speech.googleapis.com/*' => Http::response(['done' => true, 'response' => ['results' => [
        'gs://test-speech-bucket/audio' => ['inlineResult' => ['transcript' => ['results' => [['alternatives' => [[
            'transcript' => 'Ẹ káàárọ̀.', 'words' => [['word' => 'Ẹ káàárọ̀.', 'startOffset' => '0s', 'endOffset' => '2s']],
        ]]]]]]],
    ]]]), 'storage.googleapis.com/*' => Http::response([], 204)]);
    (new PollGoogleTranscription($record->id))->handle(app(GoogleSpeechClient::class), app(TranscriptionCompletion::class), app(GoogleSpeechAudioStorage::class));
    expect($record->refresh()->segments[0]['speaker'])->toBeNull()->and($record->segments[0]['end'])->toEqual(2);
});

test('provider completion rolls back transcript changes when the outbox cannot be written', function () {
    $record = Transcription::factory()->create(['provider' => 'elevenlabs', 'provider_request_id' => 'request-123']);
    $this->mock(OutboxService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Outbox unavailable'));
    expect(fn () => app(TranscriptionCompletion::class)->complete($record->id, 'elevenlabs', 'request-123', 'Hello.', []))->toThrow(RuntimeException::class);
    expect($record->refresh()->status)->toBe('pending')->and($record->transcript)->toBeNull()->and(OutboxEvent::count())->toBe(0);
});

test('new provider quotes reserve credits and consume them once on duplicate completion', function (string $provider) {
    Queue::fake();
    config()->set('billing.free_credits', '100');
    config()->set('transcriber.fallback', $provider);
    config()->set('transcriber.language_providers', []);
    $model = $provider === 'google' ? 'chirp_3' : 'scribe_v2';
    config()->set('billing.rates.transcription.'.$provider.'.'.$model.'.credits', '10');
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->mock(MediaDurationInspectorInterface::class)->shouldReceive('measure')->andReturn([
        'duration_ms' => 60000, 'audio_url' => 'https://storage.example.com/audio.wav',
        'audio_storage_path' => 'uploads/audio.wav', 'file_name' => 'audio.wav',
    ]);
    $quote = $this->postJson('/api/v1/billing/quotes', [
        'client_key' => (string) Str::uuid(), 'audio_url' => 'https://audio.example.com/audio.wav', 'language_code' => 'en',
    ])->assertAccepted()->json();
    app(UsageQuoteService::class)->measure($quote['id']);
    config()->set('transcriber.fallback', 'deepgram');
    config()->set('transcriber.'.$provider.'.model', 'future-model');
    $response = $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertSuccessful()->json();
    $record = Transcription::where('user_id', $user->id)->sole();
    expect($record->provider)->toBe($provider);
    $credits = app(CreditService::class);
    expect($credits->balance($user->id)['reserved_units'])->toBe(1000);
    $completion = app(TranscriptionCompletion::class);
    $completion->complete($record->id, $provider, 'request-123', 'Hello.', []);
    $completion->complete($record->id, $provider, 'request-123', 'Duplicate.', []);
    expect($credits->balance($user->id))->toMatchArray(['available_units' => 9000, 'reserved_units' => 0]);
    $this->assertDatabaseHas('usage_charges', ['transcription_id' => $record->id, 'status' => 'consumed', 'provider' => $provider, 'model' => $model]);
})->with(['google', 'elevenlabs']);
