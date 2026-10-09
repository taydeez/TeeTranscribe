<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Services\DubbingService;
use App\Domain\Dubbing\Services\ProcessDubbing;
use App\Infrastructure\AI\Dubbing\R2DubbingMedia;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentDubbingRepository;
use App\Jobs\ProcessDubbing as DubbingJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
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
    Cache::flush();
    Storage::fake('r2');
    config(['dubbing.provider' => 'heygen', 'dubbing.heygen.key' => 'heygen-test-key', 'dubbing.heygen.mode' => 'precision',
        'billing.free_credits' => '0', 'billing.rates.dubbing.heygen.precision.credits' => '20']);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    app(CreditService::class)->purchase($this->user->id, 10000, 'test-funding');
    $this->upload = UploadSession::create(['user_id' => $this->user->id, 'client_key' => (string) Str::uuid(), 'filename' => 'Interview.mp4',
        'content_type' => 'video/mp4', 'size' => 1000, 'fingerprint' => str_repeat('a', 64), 'storage_path' => 'audio/'.Str::ulid().'.mp4',
        'part_size' => 16 * 1024 * 1024, 'expires_at' => now()->addDays(7), 'status' => 'completed']);
    $this->media = $this->mock(DubbingMediaInterface::class, function ($mock) {
        $mock->shouldReceive('inspect')->andReturn(['size' => 1000, 'duration_ms' => 60000]);
        $mock->shouldReceive('sourceUrl')->andReturn('https://r2.example/video.mp4');
    });
    $this->input = ['client_key' => (string) Str::uuid(), 'video_storage_path' => $this->upload->storage_path,
        'source_language' => 'en', 'target_language' => 'French', 'name' => 'French interview'];
    $this->providerStatus = 'running';
    $this->projectId = 'tr_test';
    $this->submissionFails = false;
    $this->recovered = false;
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/languages')) {
            return Http::response(['data' => ['languages' => ['French', 'English', 'Japanese', 'Spanish (Spain)', 'Hausa']]]);
        }
        if ($request->method() === 'POST') {
            return $this->submissionFails ? Http::response(['error' => ['code' => 'unavailable']], 503)
                : Http::response(['data' => ['video_translation_ids' => [$this->projectId]]]);
        }
        if (str_contains($request->url(), '?limit=')) {
            return Http::response(['data' => $this->recovered ? [['id' => 'tr_test', 'callback_id' => Dubbing::sole()->id,
                'title' => 'dubbing:'.Dubbing::sole()->id]] : [], 'has_more' => false]);
        }

        return Http::response(['data' => ['id' => $this->projectId, 'status' => $this->providerStatus, 'output_language' => 'French',
            'video_url' => 'https://resource2.heygen.ai/dubbed.mp4', 'failure_message' => 'Insufficient provider credits']]);
    });
});

function confirmedHeyGenDubbing(object $test): Dubbing
{
    $quote = $test->postJson('/api/v1/dubbings/quotes', $test->input)->assertAccepted()->json();
    app(DubbingService::class)->measure($quote['id']);
    $test->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertAccepted();

    return Dubbing::sole();
}

test('the dubbing form receives the active provider targets and separate spoken language codes', function () {
    $response = $this->getJson('/api/v1/dubbings/languages')->assertOk()->assertJsonPath('configured', true);
    expect(array_column($response->json('data'), 'code'))->toBe(['Hausa', 'English', 'French', 'Japanese', 'Spanish (Spain)']);
    expect(array_column($response->json('sourceLanguages'), 'code'))->toContain('en', 'fr', 'yo');
    $this->getJson('/api/v1/dubbings/languages')->assertOk();
    Http::assertSentCount(1);
});

test('a HeyGen quote keeps its provider price and options when configuration changes before submission', function () {
    config(['dubbing.heygen.speaker_num' => 2]);
    $quote = $this->postJson('/api/v1/dubbings/quotes', $this->input)->assertAccepted()->json();
    expect($quote)->not->toHaveKey('provider')->not->toHaveKey('model');
    config(['dubbing.provider' => 'elevenlabs', 'dubbing.heygen.mode' => 'speed', 'dubbing.heygen.speaker_num' => 4]);
    app(DubbingService::class)->measure($quote['id']);
    $this->getJson('/api/v1/dubbings/quotes/'.$quote['id'])->assertOk()->assertJsonPath('credit_units', 2000);
    $this->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertAccepted();
    $this->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertAccepted();
    $record = Dubbing::sole();
    expect($record->provider)->toBe('heygen')->and($record->model)->toBe('precision')->and($record->provider_options['speaker_num'])->toBe(2);
    expect(app(ProcessDubbing::class)->handle($record->id))->toBeFalse();
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://api.heygen.com/v3/video-translations'
        && $request->hasHeader('Idempotency-Key', 'dubbing:'.$record->id) && $request['mode'] === 'precision'
        && $request['speaker_num'] === 2 && $request['output_languages'] === ['French'] && $request['input_language'] === 'en');
});

test('HeyGen completion saves video and audio before acknowledging and consumes credits once', function () {
    $record = confirmedHeyGenDubbing($this);
    $event = app(OutboxService::class)->record('dubbing:'.$record->id.':process', 'DubbingRequested', $record->id, []);
    $job = new DubbingJob($record->id, $event->id);
    $processor = app(ProcessDubbing::class);
    $job->handle($processor, app(OutboxService::class));
    expect($record->refresh()->status)->toBe('processing')->and($event->refresh()->published_at)->toBeNull();
    $this->providerStatus = 'completed';
    $this->media->shouldReceive('storeVideo')->once()->withArgs(fn ($entity, $url) => $entity->provider === 'heygen' && $url === 'https://resource2.heygen.ai/dubbed.mp4')
        ->andReturn(['video_storage_path' => 'dub/video.mp4', 'audio_storage_path' => 'dub/audio.flac']);
    $job->handle($processor, app(OutboxService::class));
    $job->handle($processor, app(OutboxService::class));
    expect($record->refresh()->status)->toBe('complete')->and($event->refresh()->published_at)->not->toBeNull();
    expect(UsageCharge::sole()->status)->toBe('consumed')->and(CreditTransaction::where('kind', 'consume')->count())->toBe(1);
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(1);
});

test('a lost HeyGen submission is recovered without creating another paid translation', function () {
    $record = confirmedHeyGenDubbing($this);
    $this->submissionFails = true;
    $processor = app(ProcessDubbing::class);
    expect(fn () => $processor->handle($record->id))->toThrow(BillingException::class);
    $this->recovered = true;
    expect($processor->handle($record->id))->toBeFalse();
    expect($record->refresh()->provider_project_id)->toBe('tr_test');
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(1);
});

test('HeyGen failure releases credits once while failed output storage retries the existing translation', function () {
    $record = confirmedHeyGenDubbing($this);
    $processor = app(ProcessDubbing::class);
    $this->providerStatus = 'failed';
    expect($processor->handle($record->id))->toBeTrue();
    $processor->fail($record->id);
    expect($record->refresh()->status)->toBe('failed')->and(CreditTransaction::where('kind', 'release')->count())->toBe(1);

    $this->input['client_key'] = (string) Str::uuid();
    $quote = $this->postJson('/api/v1/dubbings/quotes', $this->input)->assertAccepted()->json();
    app(DubbingService::class)->measure($quote['id']);
    $id = $this->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertAccepted()->json('id');
    $this->projectId = 'tr_other';
    $this->providerStatus = 'completed';
    $this->media->shouldReceive('storeVideo')->once()->andThrow(new RuntimeException('R2 unavailable'));
    $this->media->shouldReceive('storeVideo')->once()->andReturn(['audio_storage_path' => 'dub/audio.flac', 'video_storage_path' => 'dub/video.mp4']);
    expect(fn () => $processor->handle($id))->toThrow(RuntimeException::class, 'R2 unavailable');
    $processor->fail($id);
    expect(UsageCharge::where('dubbing_id', $id)->sole()->status)->toBe('consumed');
    $processor->retryExports($id, $this->user->id);
    expect($processor->handle($id))->toBeTrue()->and(Dubbing::find($id)->status)->toBe('complete');
    expect(CreditTransaction::where('kind', 'consume')->count())->toBe(1);
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(2);
});

test('HeyGen media retains returned lip synced visuals and dynamic duration rather than the source video', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    $this->app->bind(DubbingMediaInterface::class, R2DubbingMedia::class);
    config(['dubbing.heygen.download_hosts' => ['8.8.8.8']]);
    $record = Dubbing::factory()->create(['user_id' => $this->user->id, 'provider' => 'heygen', 'model' => 'precision', 'duration_ms' => 60000]);
    Http::fake(['https://8.8.8.8/*' => Http::response('heygen-lip-synced-video')]);
    Process::fake(function ($process) {
        if (in_array('-show_entries', $process->command, true)) {
            return Process::result(output: json_encode(['format' => ['duration' => 70],
                'streams' => [['codec_type' => 'video', 'codec_name' => 'h264'], ['codec_type' => 'audio', 'codec_name' => 'aac']]]));
        }
        $input = $process->command[array_search('-i', $process->command, true) + 1];
        expect(file_get_contents($input))->toBe('heygen-lip-synced-video');
        file_put_contents(end($process->command), in_array('mp4', $process->command, true) ? 'heygen-output-video' : 'heygen-output-flac');

        return Process::result();
    });
    $paths = app(R2DubbingMedia::class)->storeVideo(app(EloquentDubbingRepository::class)->find($record->id), 'https://8.8.8.8/output.mp4');
    expect(Storage::disk('r2')->get($paths['video_storage_path']))->toBe('heygen-output-video')
        ->and(Storage::disk('r2')->get($paths['audio_storage_path']))->toBe('heygen-output-flac');
    Process::assertDidntRun(fn ($process) => in_array('1:a:0', $process->command, true) || in_array('-t', $process->command, true));
});

test('HeyGen output storage rejects untrusted download URLs and incomplete uploads', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    $this->app->bind(DubbingMediaInterface::class, R2DubbingMedia::class);
    $record = Dubbing::factory()->create(['user_id' => $this->user->id, 'provider' => 'heygen', 'model' => 'precision']);
    $entity = app(EloquentDubbingRepository::class)->find($record->id);
    expect(fn () => app(R2DubbingMedia::class)->storeVideo($entity, 'https://attacker.example/output.mp4'))->toThrow(RuntimeException::class, 'URL is invalid');
    Http::assertNothingSent();
    config(['dubbing.heygen.download_hosts' => ['8.8.8.8']]);
    Http::fake(['https://8.8.8.8/*' => Http::response('video-bytes')]);
    Process::fake(function ($process) {
        if (in_array('-show_entries', $process->command, true)) {
            return Process::result(output: json_encode(['format' => ['duration' => 60], 'streams' => [['codec_type' => 'video'], ['codec_type' => 'audio']]]));
        }
        file_put_contents(end($process->command), 'output');

        return Process::result();
    });
    Storage::shouldReceive('disk')->with('r2')->andReturn($disk = Mockery::mock());
    $disk->shouldReceive('put')->once()->andReturn(true);
    $disk->shouldReceive('exists')->once()->andReturn(true);
    $disk->shouldReceive('size')->once()->andReturn(1);
    expect(fn () => app(R2DubbingMedia::class)->storeVideo($entity, 'https://8.8.8.8/output.mp4'))->toThrow(RuntimeException::class, 'upload could not be verified');
});
