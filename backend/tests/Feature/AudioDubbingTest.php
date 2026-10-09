<?php

use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\AudioDubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Services\DubbingService;
use App\Domain\Dubbing\Services\ProcessDubbing;
use App\Infrastructure\AI\Dubbing\R2DubbedAudioStorage;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentDubbingRepository;
use App\Jobs\ProcessDubbing as DubbingJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
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
    config(['dubbing.audio_provider' => 'elevenlabs', 'dubbing.provider' => 'heygen', 'dubbing.heygen.key' => null,
        'dubbing.key' => 'test-key', 'billing.free_credits' => '0', 'billing.rates.dubbing.elevenlabs.dubbing_v2.credits' => '10']);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    app(CreditService::class)->purchase($this->user->id, 10000, 'test-funding');
    $this->upload = UploadSession::create(['user_id' => $this->user->id, 'client_key' => (string) Str::uuid(), 'filename' => 'Interview.mp3',
        'content_type' => 'audio/mpeg', 'size' => 1000, 'fingerprint' => str_repeat('d', 64), 'storage_path' => 'audio/'.Str::ulid().'.mp3',
        'part_size' => 16 * 1024 * 1024, 'expires_at' => now()->addDays(7), 'status' => 'completed']);
    $this->mock(DubbingMediaInterface::class, function ($mock) {
        $mock->shouldNotReceive('inspect', 'store', 'storeVideo', 'prepareOriginal');
        $mock->shouldReceive('sourceUrl')->andReturn('https://r2.example/english.mp3');
        $mock->shouldReceive('url')->andReturnUsing(fn ($path, $name, $download = false) => $path === null ? null : 'https://r2.example/'.$path);
    });
    $this->storageFails = false;
    $this->providerFails = false;
    $this->storageCalls = 0;
    $this->mock(AudioDubbingMediaInterface::class, function ($mock) {
        $mock->shouldReceive('inspect')->andReturn(['size' => 1000, 'duration_ms' => 90500]);
        $mock->shouldReceive('store')->andReturnUsing(function ($record, $url) {
            $this->storageCalls++;
            expect($record->mediaType)->toBe('audio')->and($url)->toBe('https://storage.googleapis.com/spanish.flac');
            if ($this->storageFails) {
                throw new RuntimeException('R2 temporarily unavailable');
            }

            return ['audio_storage_path' => 'dub/spanish.flac', 'audio_preview_storage_path' => 'dub/spanish.mp3'];
        });
    });
    Http::fake(function ($request) {
        if ($request->method() === 'POST') {
            return Http::response(['project_id' => 'proj_audio', 'language_ids' => ['lang_es']]);
        }
        if (str_contains($request->url(), '/language/')) {
            return Http::response(['project_id' => 'proj_audio', 'language_id' => 'lang_es', 'status' => $this->providerFails ? 'failed' : 'completed',
                'target_language' => 'es', 'revision' => 0, 'output_revision' => 0, 'outputs' => ['lossless_audio' => 'https://storage.googleapis.com/spanish.flac']]);
        }

        return Http::response(['project_id' => 'proj_audio', 'status' => 'ready', 'language_ids' => ['lang_es']]);
    });
    $this->input = ['client_key' => (string) Str::uuid(), 'media_type' => 'audio', 'operation' => 'dubbing',
        'audio_storage_path' => $this->upload->storage_path, 'source_language' => 'en', 'target_language' => 'es', 'name' => 'Spanish interview'];
});

function confirmAudioDub(object $test): Dubbing
{
    $quote = $test->postJson('/api/v1/dubbings/quotes', $test->input)->assertAccepted()->json();
    app(DubbingService::class)->measure($quote['id']);
    $test->getJson('/api/v1/dubbings/quotes/'.$quote['id'])->assertOk()->assertJsonPath('credit_units', 1509);
    $test->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertAccepted()->assertJsonPath('mediaType', 'audio')
        ->assertJsonPath('audioUrl', null)->assertJsonPath('videoUrl', null)->assertJsonPath('subtitlesEnabled', false);
    $test->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertAccepted();

    return Dubbing::sole();
}

test('audio dubbing has an independent configured catalog when the video provider is unavailable', function () {
    $catalog = $this->getJson('/api/v1/dubbings/languages')->assertOk()->assertJsonPath('configured', false)
        ->assertJsonPath('audioDubbing.configured', true)->json('audioDubbing');
    expect(array_column($catalog['data'], 'code'))->toContain('es', 'yo', 'ha', 'en');
    Http::assertNothingSent();
});

test('audio dubbing confirms and consumes credits once and exposes only verified audio downloads', function () {
    $record = confirmAudioDub($this);
    $event = OutboxEvent::where('event_type', 'DubbingRequested')->sole();
    $job = new DubbingJob($record->id, $event->id);
    $job->handle(app(ProcessDubbing::class), app(OutboxService::class));
    $job->handle(app(ProcessDubbing::class), app(OutboxService::class));
    $this->getJson('/api/v1/dubbings/'.$record->id)->assertOk()->assertJsonPath('status', 'complete')
        ->assertJsonPath('mediaType', 'audio')->assertJsonPath('audioUrl', 'https://r2.example/dub/spanish.mp3')
        ->assertJsonPath('audioMp3DownloadUrl', 'https://r2.example/dub/spanish.mp3')->assertJsonPath('audioDownloadUrl', 'https://r2.example/dub/spanish.flac')
        ->assertJsonPath('videoUrl', null)->assertJsonPath('videoDownloadUrl', null)->assertJsonPath('subtitleDownloadUrl', null);
    expect($record->refresh()->video_storage_path)->toBeNull()->and($this->storageCalls)->toBe(1)
        ->and(OutboxEvent::where('event_type', 'DubbingSubtitlesRequested')->count())->toBe(0);
    expect(UsageCharge::count())->toBe(1)->and(UsageCharge::sole()->status)->toBe('consumed');
    expect(CreditTransaction::where('kind', 'reserve')->count())->toBe(1)->and(CreditTransaction::where('kind', 'consume')->count())->toBe(1);
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(1);
    Http::assertSent(function ($request) {
        $data = collect($request->data())->pluck('contents', 'name')->all();

        return $request->method() === 'POST' && ($data['source_url'] ?? null) === 'https://r2.example/english.mp3'
            && ($data['target_language'] ?? null) === 'es' && ($data['source_language'] ?? null) === 'en';
    });
    expect($event->refresh()->published_at)->not->toBeNull();
});

test('audio download retries use the saved provider project without reserving or charging again', function () {
    $record = confirmAudioDub($this);
    $this->storageFails = true;
    expect(fn () => app(ProcessDubbing::class)->handle($record->id))->toThrow(RuntimeException::class);
    app(ProcessDubbing::class)->fail($record->id);
    expect($record->refresh()->provider_completed_at)->not->toBeNull()->and(UsageCharge::sole()->status)->toBe('consumed');
    $this->postJson('/api/v1/dubbings/'.$record->id.'/retry')->assertOk()->assertJsonPath('status', 'processing');
    $this->postJson('/api/v1/dubbings/'.$record->id.'/retry')->assertOk();
    config(['dubbing.audio_provider' => 'unsupported-future-provider']);
    $this->storageFails = false;
    app(ProcessDubbing::class)->handle($record->id);
    expect($record->refresh()->status)->toBe('complete')->and($record->provider)->toBe('elevenlabs');
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(1);
    expect(CreditTransaction::where('kind', 'consume')->count())->toBe(1)->and(CreditTransaction::where('kind', 'reserve')->count())->toBe(1)
        ->and(CreditTransaction::where('kind', 'release')->count())->toBe(0)->and($this->storageCalls)->toBe(2);
});

test('provider failure returns reserved audio credits once and hides unfinished downloads', function () {
    $record = confirmAudioDub($this);
    $this->providerFails = true;
    app(ProcessDubbing::class)->handle($record->id);
    app(ProcessDubbing::class)->handle($record->id);
    $this->getJson('/api/v1/dubbings/'.$record->id)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('audioUrl', null);
    expect(UsageCharge::sole()->status)->toBe('released')->and(CreditTransaction::where('kind', 'release')->count())->toBe(1)
        ->and($this->storageCalls)->toBe(0);
});

test('audio dubbing uploads quotes and downloads remain scoped to their owner', function () {
    $quote = $this->postJson('/api/v1/dubbings/quotes', $this->input)->assertAccepted()->json();
    $record = Dubbing::factory()->create(['user_id' => $this->user->id, 'media_type' => 'audio', 'audio_preview_storage_path' => 'private.mp3']);
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/dubbings/quotes', $this->input)->assertNotFound();
    $this->getJson('/api/v1/dubbings/quotes/'.$quote['id'])->assertNotFound();
    $this->getJson('/api/v1/dubbings/'.$record->id)->assertNotFound();
    $this->postJson('/api/v1/dubbings/'.$record->id.'/retry')->assertNotFound();
});

test('audio inspection and conversion produce playable MP3 and lossless FLAC without video rendering', function () {
    $ffmpeg = (string) config('dubbing.ffmpeg');
    if (! Process::run([$ffmpeg, '-version'])->successful()) {
        $this->markTestSkipped('FFmpeg is unavailable.');
    }
    config(['dubbing.download_hosts' => ['8.8.8.8']]);
    $source = tempnam(sys_get_temp_dir(), 'audio-dub-source-');
    try {
        $generated = Process::timeout(30)->run([$ffmpeg, '-nostdin', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2',
            '-c:a', 'flac', '-f', 'flac', $source]);
        expect($generated->successful())->toBeTrue($generated->errorOutput());
        $bytes = file_get_contents($source);
        Storage::disk('r2')->put('source.flac', $bytes);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://8.8.8.8/*' => Http::response($bytes)]);
        $storage = app(R2DubbedAudioStorage::class);
        expect($storage->inspect('source.flac'))->toBe(['size' => strlen($bytes), 'duration_ms' => 2000]);
        $model = Dubbing::factory()->create(['user_id' => $this->user->id, 'media_type' => 'audio', 'source_storage_path' => 'source.flac', 'duration_ms' => 2000]);
        $record = app(EloquentDubbingRepository::class)->find($model->id);
        $paths = $storage->store($record, 'https://8.8.8.8/spanish.flac');
        expect(Storage::disk('r2')->size($paths['audio_storage_path']))->toBeGreaterThan(0);
        expect(Storage::disk('r2')->size($paths['audio_preview_storage_path']))->toBeGreaterThan(0);
        expect(Storage::disk('r2')->allFiles('dubbings/'.$record->id))->toHaveCount(2);
        expect($storage->inspect($paths['audio_preview_storage_path'])['duration_ms'])->toBeGreaterThanOrEqual(2000)->toBeLessThan(2100);
    } finally {
        if (is_file($source)) {
            unlink($source);
        }
    }
});
