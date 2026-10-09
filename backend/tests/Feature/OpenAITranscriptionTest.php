<?php

use App\Domain\Billing\Contracts\MediaDurationInspectorInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Billing\Services\UsageQuoteService;
use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;
use App\Domain\Transcriber\Contracts\TranscriptionPollingDispatcherInterface;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Infrastructure\AI\Transcriber\OpenAI\OpenAITranscriptionAudio;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Jobs\SubmitTranscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('r2');
    Storage::disk('r2')->put('uploads/verified-audio.wav', 'verified-audio');
    config(['openai.key' => 'test-openai-key', 'openai.endpoint' => 'https://api.openai.com/v1/',
        'transcriber.fallback' => 'openai', 'transcriber.language_providers' => [],
        'transcriber.openai.model' => 'gpt-4o-transcribe-diarize', 'billing.free_credits' => '0',
        'billing.rates.transcription.openai.gpt-4o-transcribe-diarize' => ['unit' => 'minute', 'credits' => '10']]);
    Process::fake(function ($process) {
        if (in_array('-c:a', $process->command, true)) {
            file_put_contents(end($process->command), 'prepared-mp3-audio');

            return Process::result();
        }

        return Process::result(output: '{"streams":[{"index":0}],"format":{"duration":"45","format_name":"mp3"}}');
    });
    Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response([
        'text' => 'Hello there. Welcome.', 'segments' => [
            ['start' => 0, 'end' => 1, 'speaker' => 'A', 'text' => 'Hello there.'],
            ['start' => 1.5, 'end' => 2.5, 'speaker' => 'B', 'text' => 'Welcome.'],
        ],
    ], 200, ['x-request-id' => 'request-openai-123'])]);
});

function submitOpenAIJob(Transcription $record, string $model = 'gpt-4o-transcribe-diarize'): SubmitTranscription
{
    $job = new SubmitTranscription($record->id, 'en', quotedModel: $model);
    $job->handle(app(TranscriberGatewayResolverInterface::class), app(TranscriptionRepositoryInterface::class), app(TranscriptionPollingDispatcherInterface::class));

    return $job;
}

test('OpenAI is an additional provider and language overrides keep the existing providers available', function () {
    $resolver = app(TranscriberGatewayResolverInterface::class);
    expect($resolver->resolve('fr')->provider())->toBe('openai')
        ->and($resolver->resolve('yo')->provider())->toBe('intron');
    config()->set('transcriber.language_providers', ['yo' => 'openai']);
    expect($resolver->resolve('yo-NG')->provider())->toBe('openai')
        ->and($resolver->resolve('en', 'deepgram')->provider())->toBe('deepgram');
});

test('a paid OpenAI transcription persists timed speakers and consumes credits only once', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $credits = app(CreditService::class);
    $credits->purchase($user->id, 10000, 'audio-test-purchase');
    $this->mock(MediaDurationInspectorInterface::class)->shouldReceive('measure')->once()->andReturn([
        'duration_ms' => 45000, 'audio_url' => 'https://storage.example.com/audio.wav',
        'audio_storage_path' => 'uploads/verified-audio.wav', 'file_name' => 'audio.wav',
    ]);
    $quote = $this->postJson('/api/v1/billing/quotes', ['client_key' => (string) Str::uuid(),
        'audio_url' => 'https://audio.example.com/audio.wav', 'language_code' => 'en'])->assertAccepted()->json();
    app(UsageQuoteService::class)->measure($quote['id']);
    $id = $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertAccepted()->json('id');
    $record = Transcription::findOrFail($id);
    expect($credits->balance($user->id)['reserved_units'])->toBe(750);
    config(['transcriber.fallback' => 'elevenlabs', 'transcriber.openai.model' => 'gpt-transcribe']);
    submitOpenAIJob($record);
    submitOpenAIJob($record);
    expect($record->refresh()->provider)->toBe('openai')->and($record->provider_request_id)->toBe('request-openai-123')
        ->and($record->status)->toBe('processing')->and($record->transcript)->toBe('Hello there. Welcome.')
        ->and($record->segments[1])->toMatchArray(['start' => 1.5, 'end' => 2.5, 'speaker' => 'Speaker 2', 'text' => 'Welcome.'])
        ->and(OutboxEvent::where('event_type', 'TranscriptionCompleted')->count())->toBe(1)
        ->and($credits->balance($user->id))->toMatchArray(['available_units' => 9250, 'reserved_units' => 0]);
    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        $parts = collect($request->data())->keyBy('name');

        return $request->hasHeader('Authorization', 'Bearer test-openai-key')
            && $parts['model']['contents'] === 'gpt-4o-transcribe-diarize'
            && $parts['response_format']['contents'] === 'diarized_json'
            && $parts['chunking_strategy']['contents'] === 'auto'
            && $parts['file']['filename'] === 'recording.mp3';
    });
    Process::assertRan(fn ($process) => in_array('32k', $process->command, true)
        && in_array('file,pipe', $process->command, true) && ! in_array('-t', $process->command, true));
    $this->assertDatabaseHas('usage_charges', ['transcription_id' => $id, 'provider' => 'openai', 'model' => 'gpt-4o-transcribe-diarize', 'status' => 'consumed']);
});

test('recordings over the supported duration cannot become a payable quote', function () {
    $user = User::factory()->create();
    $this->mock(MediaDurationInspectorInterface::class)->shouldReceive('measure')->andReturn([
        'duration_ms' => 5400001, 'audio_url' => 'https://storage.example.com/audio.wav',
        'audio_storage_path' => 'uploads/verified-audio.wav', 'file_name' => 'audio.wav',
    ]);
    $quotes = app(UsageQuoteService::class);
    $quote = $quotes->create($user->id, ['audio_url' => 'https://example.com/audio', 'language_code' => 'en'], (string) Str::uuid());
    expect(fn () => $quotes->measure($quote['id']))->toThrow(BillingException::class, '90 minutes');
    $this->assertDatabaseHas('billing_quotes', ['id' => $quote['id'], 'status' => 'failed',
        'failure_reason' => 'This transcription option supports recordings up to 90 minutes long.']);
    $this->assertDatabaseCount('usage_charges', 0);
    $this->assertDatabaseCount('transcriptions', 0);
    Http::assertNothingSent();
});

test('completion retries reuse the durable response if the atomic outbox write failed', function () {
    $record = Transcription::factory()->create(['provider' => 'openai', 'audio_storage_path' => 'uploads/verified-audio.wav', 'duration' => 45]);
    $outbox = $this->mock(OutboxService::class);
    $outbox->shouldReceive('record')->once()->andThrow(new RuntimeException('Outbox unavailable'));
    expect(fn () => submitOpenAIJob($record))->toThrow(RuntimeException::class, 'Outbox unavailable');
    expect($record->refresh()->status)->toBe('pending')->and($record->transcript)->toBeNull();
    $this->app->forgetInstance(OutboxService::class);
    $this->app->forgetInstance(TranscriberGatewayResolverInterface::class);
    submitOpenAIJob($record);
    expect($record->refresh()->status)->toBe('processing')->and(OutboxEvent::where('event_type', 'TranscriptionCompleted')->count())->toBe(1);
    Http::assertSentCount(1);
});

test('exhausted provider failures record failure and return reserved credits', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    app(CreditService::class)->purchase($user->id, 10000, 'failure-test-purchase');
    $this->mock(MediaDurationInspectorInterface::class)->shouldReceive('measure')->andReturn([
        'duration_ms' => 45000, 'audio_url' => 'https://storage.example.com/audio.wav',
        'audio_storage_path' => 'uploads/verified-audio.wav', 'file_name' => 'audio.wav',
    ]);
    $quotes = app(UsageQuoteService::class);
    $quote = $quotes->create($user->id, ['audio_url' => 'https://example.com/audio', 'language_code' => 'en'], (string) Str::uuid());
    $quotes->measure($quote['id']);
    $id = $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertAccepted()->json('id');
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['error' => ['type' => 'invalid_request_error']], 400)]);
    $record = Transcription::findOrFail($id);
    expect(fn () => submitOpenAIJob($record))->toThrow(RequestException::class);
    (new SubmitTranscription($id, 'en'))->failed(new RuntimeException('Provider unavailable'));
    expect($record->refresh()->status)->toBe('failed')
        ->and(app(CreditService::class)->balance($user->id))->toMatchArray(['available_units' => 10000, 'reserved_units' => 0])
        ->and(OutboxEvent::where('event_type', 'TranscriptionCompleted')->count())->toBe(0);
});

test('text-only models do not manufacture timestamps or speaker labels', function () {
    $record = Transcription::factory()->create(['provider' => 'openai', 'audio_storage_path' => 'uploads/verified-audio.wav', 'duration' => 45]);
    submitOpenAIJob($record, 'gpt-transcribe');
    expect($record->refresh()->segments)->toBe([])->and($record->transcript)->toBe('Hello there. Welcome.');
    Http::assertSent(function ($request) {
        $parts = collect($request->data())->keyBy('name');

        return $parts['response_format']['contents'] === 'json'
            && isset($parts['languages[]']) && ! isset($parts['language']) && ! isset($parts['chunking_strategy']);
    });
});

test('actual FFmpeg conversion preserves the entire verified audio within the upload limit', function () {
    Process::swap(new Factory);
    $source = tempnam(sys_get_temp_dir(), 'openai-test-');
    $prepared = null;
    try {
        $result = Process::timeout(30)->run([(string) config('transcriber.openai.ffmpeg', 'ffmpeg'), '-nostdin', '-y', '-v', 'error',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2', '-f', 'wav', $source]);
        expect($result->successful())->toBeTrue();
        $stream = fopen($source, 'rb');
        Storage::disk('r2')->put('uploads/source.wav', $stream);
        fclose($stream);
        $prepared = app(OpenAITranscriptionAudio::class)->prepare('uploads/source.wav', 2);
        expect($prepared['duration'])->toEqual(2)->and(filesize($prepared['path']))->toBeGreaterThan(0)->toBeLessThan(24 * 1024 * 1024);
        Http::assertNothingSent();
    } finally {
        unlink($source);
        if ($prepared !== null) {
            unlink($prepared['path']);
        }
    }
});
