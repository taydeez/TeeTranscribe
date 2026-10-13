<?php

use App\Domain\Admin\AIProvider\Services\AIProviderConfigurationService;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\AudioDubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Services\DubbingService;
use App\Domain\Dubbing\Services\ProcessDubbing;
use App\Infrastructure\AI\ConfigProviderCatalog;
use App\Infrastructure\AI\Dubbing\Google\GoogleDubbingAudio;
use App\Infrastructure\AI\Dubbing\Google\GoogleDubbingGateway;
use App\Infrastructure\AI\Dubbing\Google\GoogleTextToSpeechClient;
use App\Infrastructure\AI\Transcriber\Google\GoogleSpeechCredentials;
use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentDubbingRepository;
use App\Infrastructure\Privacy\R2PrivacyStorage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Cache::flush();
    Storage::fake('r2');
    config(['dubbing.provider' => 'google', 'dubbing.audio_provider' => 'google',
        'transcriber.google.project' => 'test-project', 'transcriber.google.credentials' => 'test-credentials',
        'transcriber.google.bucket' => 'test-bucket', 'transcriber.google.location' => 'us',
        'transcriber.google.word_timestamps' => false, 'transcriber.google.model' => 'chirp_3',
        'translation.google.key' => 'test-key', 'billing.free_credits' => '0',
        'billing.rates.dubbing.google.chirp3-hd.credits' => '10']);
    $this->mock(GoogleSpeechCredentials::class)->shouldReceive('token')->andReturn('test-token');
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    app(CreditService::class)->purchase($this->user->id, 10000, 'test-funding');
    $this->upload = UploadSession::create(['user_id' => $this->user->id, 'client_key' => (string) Str::uuid(),
        'filename' => 'Interview.mp4', 'content_type' => 'video/mp4', 'size' => 1000,
        'fingerprint' => str_repeat('a', 64), 'storage_path' => 'audio/'.Str::ulid().'.mp4',
        'part_size' => 16 * 1024 * 1024, 'expires_at' => now()->addDays(7), 'status' => 'completed']);
    $this->media = $this->mock(DubbingMediaInterface::class);
    $this->media->shouldReceive('inspect')->andReturn(['size' => 1000, 'duration_ms' => 60000]);
    $this->media->shouldReceive('sourceUrl')->andReturn('https://r2.example/video.mp4');
    $this->audio = $this->mock(GoogleDubbingAudio::class);
    $this->audio->shouldReceive('stage')->andReturnUsing(function ($id) {
        $path = 'dubbings/'.$id.'/google/source.flac';
        Storage::disk('r2')->put($path, 'extracted-audio');

        return $path;
    });
    $this->audio->shouldReceive('render')->andReturnUsing(function ($id, $segments) {
        expect($segments[0]['translation'])->toBe('Bonjour.');
        Storage::disk('r2')->put('dubbings/'.$id.'/google/audio.flac', 'dubbed-audio');

        return 'r2://dubbings/'.$id.'/google/audio.flac';
    });
    $this->input = ['client_key' => (string) Str::uuid(), 'video_storage_path' => $this->upload->storage_path,
        'source_language' => 'en', 'target_language' => 'fr', 'name' => 'French interview'];
    $this->done = false;
    $this->recognitionError = false;
    $this->synthesisFails = false;
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/v1/voices')) {
            return Http::response(['voices' => [
                ['name' => 'fr-FR-Chirp3-HD-Aoede', 'languageCodes' => ['fr-FR']],
                ['name' => 'en-US-Chirp3-HD-Aoede', 'languageCodes' => ['en-US']],
                ['name' => 'yo-NG-Standard-A', 'languageCodes' => ['yo-NG']],
            ]]);
        }
        if (str_contains($request->url(), ':batchRecognize')) {
            return Http::response(['name' => 'projects/test-project/locations/us/operations/test-operation']);
        }
        if (str_contains($request->url(), '/operations/')) {
            if ($this->recognitionError) {
                return Http::response(['done' => true, 'error' => ['code' => 3, 'message' => 'Invalid audio']]);
            }

            return Http::response($this->done ? ['done' => true, 'response' => ['results' => [
                'gs://test-bucket/audio' => ['inlineResult' => ['transcript' => ['results' => [
                    ['alternatives' => [['words' => [['word' => 'Hello.', 'endOffset' => '1s', 'speakerLabel' => '0']]]]],
                ]]]],
            ]]] : ['done' => false]);
        }
        if (str_contains($request->url(), 'translation.googleapis.com')) {
            return Http::response(['data' => ['translations' => [['translatedText' => 'Bonjour.']]]]);
        }
        if (str_contains($request->url(), 'text:synthesize')) {
            return $this->synthesisFails ? Http::response(['error' => ['message' => 'Temporary error']], 503)
                : Http::response(['audioContent' => base64_encode('RIFF'.str_repeat('0', 4).'WAVE'.str_repeat('0', 40))]);
        }
        if (str_contains($request->url(), 'storage.googleapis.com')) {
            return Http::response([], $request->method() === 'DELETE' ? 204 : 200);
        }

        throw new RuntimeException('Unexpected HTTP request: '.$request->url());
    });
});

function confirmedGoogleDubbing(object $test): Dubbing
{
    $quote = $test->postJson('/api/v1/dubbings/quotes', $test->input)->assertAccepted()->json();
    app(DubbingService::class)->measure($quote['id']);
    $test->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertAccepted();

    return Dubbing::sole();
}

test('Google is configurable for audio and video dubbing and only exposes supported speech voices', function () {
    $catalog = new ConfigProviderCatalog;
    expect($catalog->providers('video_dubbing')['google'])->toMatchArray(['configured' => true, 'model' => 'chirp3-hd', 'custom_models' => false]);
    expect($catalog->providers('audio_dubbing'))->toHaveKey('google');
    $this->getJson('/api/v1/dubbings/languages')->assertOk()->assertJsonPath('configured', true)
        ->assertJsonPath('audioDubbing.configured', true);
    expect(array_column(app(GoogleTextToSpeechClient::class)->languages(), 'code'))->toContain('en', 'fr')->not->toContain('yo', 'ha');
});

test('Google dubbing keeps quote voice and pricing snapshots and completes through the existing credit lifecycle', function () {
    $record = confirmedGoogleDubbing($this);
    expect($record->provider_options['voice']['name'])->toBe('fr-FR-Chirp3-HD-Aoede');
    config(['dubbing.google.voice' => 'Charon', 'billing.rates.dubbing.google.chirp3-hd.credits' => '500']);
    $processor = app(ProcessDubbing::class);
    expect($processor->handle($record->id))->toBeFalse();
    expect($processor->handle($record->id))->toBeFalse();
    $this->done = true;
    $this->media->shouldReceive('store')->once()->withArgs(fn ($entity, $url) => $url === 'r2://dubbings/'.$entity->id.'/google/audio.flac')
        ->andReturn(['audio_storage_path' => 'dubbings/'.$record->id.'/audio.flac', 'video_storage_path' => 'dubbings/'.$record->id.'/video.mp4']);
    expect($processor->handle($record->id))->toBeTrue();
    expect($processor->handle($record->id))->toBeTrue();
    expect($record->refresh()->status)->toBe('complete')->and(UsageCharge::sole()->status)->toBe('consumed');
    expect(CreditTransaction::where('kind', 'consume')->count())->toBe(1);
    Http::assertSent(fn ($r) => str_contains($r->url(), ':batchRecognize') && ((array) $r['config']['features'])['enableWordTimeOffsets'] === true);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'text:synthesize') && $r['voice']['name'] === 'fr-FR-Chirp3-HD-Aoede');
    expect(Http::recorded(fn ($r) => str_contains($r->url(), ':batchRecognize')))->toHaveCount(1);
    Storage::disk('r2')->assertMissing('dubbings/'.$record->id.'/google/source.flac');
    Storage::disk('r2')->assertMissing('dubbings/'.$record->id.'/google/segments/0.wav');
    $entity = app(EloquentDubbingRepository::class)->find($record->id);
    expect(app(GoogleDubbingGateway::class)->subtitles($entity)['segments'][0])->toMatchArray(['start' => 0, 'end' => 1, 'text' => 'Bonjour.']);
});

test('Google dubbing resumes a failed synthesis without resubmitting recognition or translation', function () {
    $record = confirmedGoogleDubbing($this);
    $processor = app(ProcessDubbing::class);
    $processor->handle($record->id);
    $this->done = true;
    $this->synthesisFails = true;
    expect(fn () => $processor->handle($record->id))->toThrow(RequestException::class);
    $this->synthesisFails = false;
    $this->media->shouldReceive('store')->andReturn(['audio_storage_path' => 'output.flac', 'video_storage_path' => 'output.mp4']);
    expect($processor->handle($record->id))->toBeTrue();
    expect(Http::recorded(fn ($r) => str_contains($r->url(), ':batchRecognize')))->toHaveCount(1);
    expect(Http::recorded(fn ($r) => str_contains($r->url(), 'translation.googleapis.com')))->toHaveCount(1);
});

test('Google provider failure releases credits and removes the staged source', function () {
    $record = confirmedGoogleDubbing($this);
    $processor = app(ProcessDubbing::class);
    $processor->handle($record->id);
    $this->recognitionError = true;
    expect($processor->handle($record->id))->toBeTrue();
    expect($record->refresh()->status)->toBe('failed')->and(UsageCharge::sole()->status)->toBe('released');
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'storage.googleapis.com'));
});

test('deleting an in-progress Google dub also removes its temporary Google source', function () {
    $record = confirmedGoogleDubbing($this);
    app(ProcessDubbing::class)->handle($record->id);
    app(R2PrivacyStorage::class)->purge(['prefixes' => ['dubbings/'.$record->id.'/']]);
    Storage::disk('r2')->assertMissing('dubbings/'.$record->id.'/google/state.json');
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'storage.googleapis.com'));
});

test('an ambiguous Google speech submission cannot create a second billable recognition request', function () {
    $record = confirmedGoogleDubbing($this);
    $processor = app(ProcessDubbing::class);
    $processor->handle($record->id);
    $path = 'dubbings/'.$record->id.'/google/state.json';
    $state = json_decode(Storage::disk('r2')->get($path), true);
    unset($state['operation']);
    Storage::disk('r2')->put($path, json_encode($state));
    expect(fn () => $processor->handle($record->id))->toThrow(RuntimeException::class, 'unknown outcome');
    expect(Http::recorded(fn ($r) => str_contains($r->url(), ':batchRecognize')))->toHaveCount(1);
});

test('audio dubbing uses the Google pipeline and the existing audio export service', function () {
    $this->upload->update(['content_type' => 'audio/mpeg', 'filename' => 'Interview.mp3']);
    $this->input = ['client_key' => (string) Str::uuid(), 'audio_storage_path' => $this->upload->storage_path,
        'media_type' => 'audio', 'source_language' => 'en', 'target_language' => 'fr'];
    $audio = $this->mock(AudioDubbingMediaInterface::class);
    $audio->shouldReceive('inspect')->andReturn(['size' => 1000, 'duration_ms' => 60000]);
    $audio->shouldReceive('store')->once()->andReturn(['audio_storage_path' => 'audio.flac', 'audio_preview_storage_path' => 'audio.mp3']);
    $record = confirmedGoogleDubbing($this);
    $processor = app(ProcessDubbing::class);
    expect($processor->handle($record->id))->toBeFalse();
    $this->done = true;
    expect($processor->handle($record->id))->toBeTrue();
    expect($record->refresh()->status)->toBe('complete')->and($record->audio_preview_storage_path)->toBe('audio.mp3');
});

test('long Google dubs recognize sequential chunks and retain absolute subtitle timing', function () {
    $record = confirmedGoogleDubbing($this);
    $record->update(['duration_ms' => 1260000]);
    $processor = app(ProcessDubbing::class);
    expect($processor->handle($record->id))->toBeFalse();
    $this->done = true;
    expect($processor->handle($record->id))->toBeFalse();
    expect($processor->handle($record->id))->toBeFalse();
    $this->media->shouldReceive('store')->andReturn(['audio_storage_path' => 'long.flac', 'video_storage_path' => 'long.mp4']);
    expect($processor->handle($record->id))->toBeTrue();
    $entity = app(EloquentDubbingRepository::class)->find($record->id);
    $segments = app(GoogleDubbingGateway::class)->subtitles($entity)['segments'];
    expect($segments)->toHaveCount(2)->and($segments[1]['start'])->toBe(1200)->and($segments[1]['end'])->toBe(1201);
    expect(Http::recorded(fn ($r) => str_contains($r->url(), ':batchRecognize')))->toHaveCount(2);
    expect(Http::recorded(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'storage.googleapis.com')))->toHaveCount(2);
});

test('Google progress is recovered when the application loses the saved project reference', function () {
    $record = confirmedGoogleDubbing($this);
    $processor = app(ProcessDubbing::class);
    $processor->handle($record->id);
    $record->update(['provider_project_id' => null, 'provider_language_id' => null]);
    expect($processor->handle($record->id))->toBeFalse();
    expect($record->refresh()->provider_project_id)->toBe($record->id);
    expect(Http::recorded(fn ($r) => str_contains($r->url(), ':batchRecognize')))->toHaveCount(1);
});

test('Google dubbing download failure retries exports without another provider charge', function () {
    $record = confirmedGoogleDubbing($this);
    $processor = app(ProcessDubbing::class);
    $processor->handle($record->id);
    $this->done = true;
    $this->media->shouldReceive('store')->once()->andThrow(new RuntimeException('R2 unavailable'));
    expect(fn () => $processor->handle($record->id))->toThrow(RuntimeException::class, 'R2 unavailable');
    $processor->fail($record->id);
    expect(UsageCharge::sole()->status)->toBe('consumed');
    $processor->retryExports($record->id, $this->user->id);
    $this->media->shouldReceive('store')->once()->andReturn(['audio_storage_path' => 'retry.flac', 'video_storage_path' => 'retry.mp4']);
    expect($processor->handle($record->id))->toBeTrue();
    expect(Http::recorded(fn ($r) => str_contains($r->url(), 'text:synthesize')))->toHaveCount(1);
    expect(CreditTransaction::where('kind', 'consume')->count())->toBe(1);
});

test('admin provider settings add new providers without changing the saved default', function () {
    $catalog = new ConfigProviderCatalog;
    $configuration = $catalog->defaults('video_dubbing');
    $configuration['default_provider'] = 'elevenlabs';
    unset($configuration['providers']['google']);
    DB::table('ai_provider_configurations')->insert([
        'id' => (string) Str::ulid(), 'activity' => 'video_dubbing', 'configuration' => json_encode($configuration),
        'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $record = app(AIProviderConfigurationService::class)->record('video_dubbing');
    expect($record['configuration']['providers']['google'])->toMatchArray(['enabled' => false, 'model' => 'chirp3-hd']);
    expect($record['configuration']['default_provider'])->toBe('elevenlabs');
});
