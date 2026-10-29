<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Services\ProcessDubbing;
use App\Infrastructure\AI\Dubbing\R2DubbingMedia;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\BillingQuote;
use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\CreditWallet;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentDubbingRepository;
use App\Jobs\ProcessDubbing as DubbingJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
beforeEach(function () {
    config(['dubbing.key' => 'test-key', 'billing.free_credits' => '0']);
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('r2');
    $this->user = User::factory()->create();
    app(CreditService::class)->purchase($this->user->id, 10000, 'test-funding');
    $this->record = Dubbing::factory()->create(['user_id' => $this->user->id]);
    $this->quote = BillingQuote::create(['user_id' => $this->user->id, 'client_key' => (string) Str::uuid(), 'activity' => 'dubbing', 'provider' => 'elevenlabs',
        'model' => 'dubbing_v2', 'status' => 'submitted', 'quantity' => 60000, 'credit_units' => 1000, 'source' => [], 'request_source' => [], 'rate' => ['unit_length' => 60000], 'expires_at' => now()->addHour(), 'dubbing_id' => $this->record->id]);
    app(CreditService::class)->reserve($this->user->id, $this->quote->toArray(), $this->record->id);
    $this->event = app(OutboxService::class)->record('dubbing:'.$this->record->id.':process', 'DubbingRequested', $this->record->id, []);
    $this->providerStatus = 'processing';
    $this->providerFails = false;
    $this->recovered = false;
    Http::fake(function ($request) {
        if ($request->method() === 'POST') {
            if ($this->providerFails) {
                return Http::response(['detail' => 'unavailable'], 503);
            }

            return Http::response(['project_id' => 'proj_test', 'language_ids' => ['lang_test'], 'status' => 'queued'], 201);
        }
        if (str_contains($request->url(), '/language/')) {
            return Http::response(['project_id' => 'proj_test', 'language_id' => 'lang_test', 'target_language' => 'fr', 'status' => $this->providerStatus,
                'revision' => 0, 'output_revision' => 0, 'outputs' => ['lossless_audio' => 'https://storage.googleapis.com/eleven-dubbing/audio.flac']]);
        }
        if (str_contains($request->url(), 'page_size=')) {
            return Http::response(['projects' => $this->recovered ? [['project_id' => 'proj_test', 'reference' => $this->record->id]] : []]);
        }

        return Http::response(['project_id' => 'proj_test', 'language_ids' => ['lang_test'], 'status' => 'ready']);
    });
    $this->media = $this->mock(DubbingMediaInterface::class, function ($mock) {
        $mock->shouldReceive('sourceUrl')->andReturn('https://r2.example/video.mp4');
    });
});
test('dubbing polls a single provider project and completes only after both outputs are stored', function () {
    $this->media->shouldReceive('store')->once()->andReturn(['audio_storage_path' => 'dubbings/audio.flac', 'video_storage_path' => 'dubbings/video.mp4']);
    $processor = app(ProcessDubbing::class);
    $job = new DubbingJob($this->record->id, $this->event->id);
    expect($job->connection)->toBe('dubbing')->and(config('queue.connections.dubbing.retry_after'))->toBeGreaterThan($job->timeout);
    $this->artisan('outbox:publish')->assertSuccessful();
    Queue::assertPushed(DubbingJob::class);
    $job->handle($processor, app(OutboxService::class));
    expect($this->record->refresh()->status)->toBe('processing')->and($this->event->refresh()->published_at)->toBeNull();
    $this->providerStatus = 'completed';
    $job->handle($processor, app(OutboxService::class));
    $job->handle($processor, app(OutboxService::class));
    expect($this->record->refresh()->status)->toBe('complete')->and($this->event->refresh()->published_at)->not->toBeNull()
        ->and(UsageCharge::sole()->status)->toBe('consumed')->and(CreditTransaction::where('kind', 'consume')->count())->toBe(1);
    $creates = Http::recorded(fn ($request) => $request->method() === 'POST');
    expect($creates)->toHaveCount(1);
});
test('provider failures release the reservation exactly once and never repeat an ambiguous creation', function () {
    $this->providerFails = true;
    $processor = app(ProcessDubbing::class);
    expect(fn () => $processor->handle($this->record->id))->toThrow(BillingException::class);
    expect(fn () => $processor->handle($this->record->id))->toThrow(BillingException::class, 'reconciliation');
    $job = new DubbingJob($this->record->id, $this->event->id);
    $job->failed(new RuntimeException('Unavailable'));
    $job->failed(new RuntimeException('Duplicate'));
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(1);
    expect($this->record->refresh()->status)->toBe('failed')->and(CreditWallet::sole()->available_units)->toBe(10000)
        ->and(UsageCharge::sole()->status)->toBe('released')->and(CreditTransaction::where('kind', 'release')->count())->toBe(1);
});
test('a lost creation response recovers the existing project by the saved purchase reference', function () {
    $processor = app(ProcessDubbing::class);
    $this->providerFails = true;
    expect(fn () => $processor->handle($this->record->id))->toThrow(BillingException::class);
    $this->recovered = true;
    expect($processor->handle($this->record->id))->toBeFalse();
    expect($this->record->refresh()->provider_project_id)->toBe('proj_test');
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(1);
});

test('failed video preparation retries the saved project without another charge or provider creation', function () {
    $this->providerStatus = 'completed';
    $this->media->shouldReceive('store')->once()->andThrow(new RuntimeException('R2 unavailable'));
    $this->media->shouldReceive('store')->once()->andReturn(['audio_storage_path' => 'dub/audio.flac', 'video_storage_path' => 'dub/video.mp4']);
    $processor = app(ProcessDubbing::class);
    expect(fn () => $processor->handle($this->record->id))->toThrow(RuntimeException::class, 'R2 unavailable');
    $processor->fail($this->record->id);
    expect($this->record->refresh()->status)->toBe('failed')->and(UsageCharge::sole()->status)->toBe('consumed');
    $processor->retryExports($this->record->id, $this->user->id);
    $processor->retryExports($this->record->id, $this->user->id);
    expect(OutboxEvent::where('event_key', 'dubbing:'.$this->record->id.':exports')->count())->toBe(1);
    expect($processor->handle($this->record->id))->toBeTrue();
    expect($this->record->refresh()->status)->toBe('complete')->and(CreditTransaction::where('kind', 'consume')->count())->toBe(1);
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(1);
});
test('provider terminal failure and processing expiry refund without attempting exports', function () {
    Log::spy();
    $this->providerStatus = 'failed';
    (new DubbingJob($this->record->id, $this->event->id))->handle(app(ProcessDubbing::class), app(OutboxService::class));
    Log::shouldHaveReceived('warning')->with('Dubbing provider reported failure.', Mockery::on(
        fn ($context) => $context['provider_project_id'] === 'proj_test' && $context['provider_language_id'] === 'lang_test' && $context['status'] === 'failed'
    ))->once();
    Log::shouldHaveReceived('warning')->with('Dubbing finished with a failed outcome.', Mockery::on(
        fn ($context) => $context['dubbing_id'] === $this->record->id && $context['provider_completed_at'] === null
    ))->once();
    expect(UsageCharge::sole()->status)->toBe('released');
    $expired = Dubbing::factory()->create(['user_id' => $this->user->id, 'created_at' => now()->subHours(25)]);
    expect(app(ProcessDubbing::class)->handle($expired->id))->toBeTrue()->and($expired->refresh()->status)->toBe('failed');
});
test('media assembly streams verified outputs to R2 and replaces the original audio track', function () {
    $this->app->bind(DubbingMediaInterface::class, R2DubbingMedia::class);
    config(['dubbing.download_hosts' => ['8.8.8.8']]);
    Storage::disk('r2')->put($this->record->source_storage_path, 'source-video-bytes');
    Http::fake(['https://8.8.8.8/*' => Http::response('dubbed-audio-bytes')]);
    Process::fake(function ($process) {
        if (in_array('-show_entries', $process->command, true)) {
            return Process::result(output: json_encode(['format' => ['duration' => 60], 'streams' => [['codec_type' => 'video', 'codec_name' => 'h264'], ['codec_type' => 'audio', 'codec_name' => 'aac']]]));
        }
        $path = $process->command[array_key_last($process->command)];
        file_put_contents($path, in_array('mp4', $process->command, true) ? 'dubbed-video-bytes' : 'dubbed-audio-flac');

        return Process::result();
    });
    $entity = app(EloquentDubbingRepository::class)->find($this->record->id);
    $paths = app(R2DubbingMedia::class)->store($entity, 'https://8.8.8.8/audio.flac');
    expect(Storage::disk('r2')->get($paths['video_storage_path']))->toBe('dubbed-video-bytes')
        ->and(Storage::disk('r2')->get($paths['audio_storage_path']))->toBe('dubbed-audio-flac');
    Process::assertRan(fn ($process) => in_array('0:v:0', $process->command, true) && in_array('1:a:0', $process->command, true) && in_array('copy', $process->command, true));
});

test('failed audio conversion logs diagnostics and does not publish output files', function () {
    $this->app->bind(DubbingMediaInterface::class, R2DubbingMedia::class);
    config(['dubbing.download_hosts' => ['8.8.8.8']]);
    Storage::disk('r2')->put($this->record->source_storage_path, 'source-video-bytes');
    Http::fake(['https://8.8.8.8/*' => Http::response('dubbed-audio-bytes')]);
    Log::shouldReceive('warning')->once()->with('Dubbing audio conversion failed.', Mockery::on(
        fn ($context) => $context['dubbing_id'] === $this->record->id && $context['exit_code'] === 1 && trim($context['error']) === 'ffmpeg executable not found'
    ));
    Process::fake(function ($process) {
        if (in_array('-show_entries', $process->command, true)) {
            return Process::result(output: json_encode(['streams' => [['codec_type' => 'video', 'codec_name' => 'h264']]]));
        }

        return Process::result(errorOutput: 'ffmpeg executable not found', exitCode: 1);
    });
    $entity = app(EloquentDubbingRepository::class)->find($this->record->id);
    expect(fn () => app(R2DubbingMedia::class)->store($entity, 'https://8.8.8.8/audio.flac'))
        ->toThrow(RuntimeException::class, 'The dubbed audio could not be decoded.');
    expect(Storage::disk('r2')->allFiles('dubbings/'.$entity->id))->toBeEmpty();
});
