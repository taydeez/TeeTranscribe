<?php

use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingSubtitleRendererInterface;
use App\Domain\Dubbing\Services\DubbingService;
use App\Domain\Dubbing\Services\ProcessDubbing;
use App\Domain\Dubbing\Services\ProcessDubbingSubtitles;
use App\Infrastructure\AI\Dubbing\R2DubbingSubtitleRenderer;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentDubbingRepository;
use App\Jobs\ProcessDubbing as DubbingJob;
use App\Jobs\RenderDubbingSubtitles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    config(['dubbing.provider' => 'elevenlabs', 'dubbing.key' => 'test-key', 'billing.free_credits' => '0',
        'billing.rates.dubbing.elevenlabs.dubbing_v2.credits' => '10', 'dubbing.subtitles.fonts_directory' => null]);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    app(CreditService::class)->purchase($this->user->id, 10000, 'test-funding');
    $upload = UploadSession::create(['user_id' => $this->user->id, 'client_key' => (string) Str::uuid(), 'filename' => 'Interview.mp4',
        'content_type' => 'video/mp4', 'size' => 1000, 'fingerprint' => str_repeat('b', 64), 'storage_path' => 'video/'.Str::ulid().'.mp4',
        'part_size' => 16 * 1024 * 1024, 'expires_at' => now()->addDays(7), 'status' => 'completed']);
    $this->media = $this->mock(DubbingMediaInterface::class, function ($mock) {
        $mock->shouldReceive('inspect')->andReturn(['size' => 1000, 'duration_ms' => 60000]);
        $mock->shouldReceive('sourceUrl')->andReturn('https://r2.example/input.mp4');
        $mock->shouldReceive('store')->andReturn(['video_storage_path' => 'dub/clean.mp4', 'audio_storage_path' => 'dub/audio.flac']);
        $mock->shouldReceive('url')->andReturnUsing(fn ($path) => $path === null ? null : 'https://r2.example/'.$path);
    });
    $this->segments = [['id' => 'segment-1', 'speaker_id' => 'speaker-1', 'start_s' => 1, 'end_s' => 3,
        'source_text' => 'Welcome!', 'translation' => 'Ẹ káàbọ̀!']];
    Http::fake(function ($request) {
        if ($request->method() === 'POST') {
            return Http::response(['project_id' => 'proj_test', 'language_ids' => ['lang_test']]);
        }
        if (str_ends_with($request->url(), '/transcript')) {
            return Http::response(['target_language' => 'yo', 'revision' => 0, 'segments' => $this->segments]);
        }
        if (str_contains($request->url(), '/language/')) {
            return Http::response(['project_id' => 'proj_test', 'language_id' => 'lang_test', 'status' => 'completed', 'target_language' => 'yo',
                'revision' => 0, 'output_revision' => 0, 'outputs' => ['lossless_audio' => 'https://storage.googleapis.com/audio.flac']]);
        }

        return Http::response(['project_id' => 'proj_test', 'status' => 'ready', 'language_ids' => ['lang_test']]);
    });
    $this->input = ['client_key' => (string) Str::uuid(), 'video_storage_path' => $upload->storage_path, 'target_language' => 'yo',
        'source_language' => 'en', 'subtitles_enabled' => true, 'subtitle_style' => 'boxed'];
});

function startSubtitleDub(object $test): Dubbing
{
    $quote = $test->postJson('/api/v1/dubbings/quotes', $test->input)->assertAccepted()->json();
    app(DubbingService::class)->measure($quote['id']);
    $test->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertAccepted()->assertJsonPath('subtitlesEnabled', true);
    $record = Dubbing::sole();
    $event = OutboxEvent::where('event_type', 'DubbingRequested')->sole();
    (new DubbingJob($record->id, $event->id))->handle(app(ProcessDubbing::class), app(OutboxService::class));

    return $record->refresh();
}

test('selected subtitles are saved with the quote and handed to a separate worker before completion', function () {
    $quote = $this->postJson('/api/v1/dubbings/quotes', $this->input)->assertAccepted()->json();
    $this->postJson('/api/v1/dubbings/quotes', $this->input)->assertAccepted()->assertJsonPath('id', $quote['id']);
    app(DubbingService::class)->measure($quote['id']);
    $this->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertAccepted();
    $this->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertAccepted();
    $record = Dubbing::sole();
    expect($record->subtitle_style)->toBe('boxed')->and($record->subtitles_enabled)->toBeTrue();
    expect(app(ProcessDubbing::class)->handle($record->id))->toBeTrue();
    expect(app(ProcessDubbing::class)->handle($record->id))->toBeTrue();
    expect($record->refresh()->status)->toBe('processing')->and($record->subtitle_status)->toBe('pending');
    expect(OutboxEvent::where('event_type', 'DubbingSubtitlesRequested')->count())->toBe(1);
    $this->getJson('/api/v1/dubbings/'.$record->id)->assertOk()->assertJsonPath('videoDownloadUrl', null);
    $this->artisan('outbox:publish')->assertSuccessful();
    Queue::assertPushed(RenderDubbingSubtitles::class, fn ($job) => $job->connection === 'subtitle_rendering'
        && $job->queue === 'subtitle-rendering' && config('queue.connections.subtitle_rendering.retry_after') > $job->timeout);
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(1);
});

test('subtitle completion uses translated timings and exposes captioned clean audio and SRT downloads', function () {
    $record = startSubtitleDub($this);
    $this->mock(DubbingSubtitleRendererInterface::class, function ($mock) {
        $mock->shouldReceive('render')->once()->withArgs(fn ($record, $captions) => $record->subtitleStyle === 'boxed'
            && $captions['segments'][0] === ['start' => 1, 'end' => 3, 'text' => 'Ẹ káàbọ̀!'])
            ->andReturn(['subtitle_storage_path' => 'dub/captions.srt', 'captioned_video_storage_path' => 'dub/captioned.mp4']);
    });
    $event = OutboxEvent::where('event_type', 'DubbingSubtitlesRequested')->sole();
    $job = new RenderDubbingSubtitles($record->id, $event->id);
    $job->handle(app(ProcessDubbingSubtitles::class), app(OutboxService::class));
    $job->handle(app(ProcessDubbingSubtitles::class), app(OutboxService::class));
    expect($record->refresh()->status)->toBe('complete')->and($record->subtitle_status)->toBe('complete');
    expect($event->refresh()->published_at)->not->toBeNull()->and(CreditTransaction::where('kind', 'consume')->count())->toBe(1);
    $this->getJson('/api/v1/dubbings/'.$record->id)->assertOk()->assertJsonPath('videoUrl', 'https://r2.example/dub/captioned.mp4')
        ->assertJsonPath('videoDownloadUrl', 'https://r2.example/dub/captioned.mp4')->assertJsonPath('cleanVideoDownloadUrl', 'https://r2.example/dub/clean.mp4')
        ->assertJsonPath('subtitleDownloadUrl', 'https://r2.example/dub/captions.srt')->assertJsonPath('audioDownloadUrl', 'https://r2.example/dub/audio.flac');
});

test('failed subtitle rendering retries saved media without another paid dub or credit reservation', function () {
    $record = startSubtitleDub($this);
    $this->mock(DubbingSubtitleRendererInterface::class, function ($mock) {
        $mock->shouldReceive('render')->once()->andThrow(new RuntimeException('Worker unavailable'));
        $mock->shouldReceive('render')->once()->andReturn(['subtitle_storage_path' => 'dub/captions.srt', 'captioned_video_storage_path' => 'dub/captioned.mp4']);
    });
    $event = OutboxEvent::where('event_type', 'DubbingSubtitlesRequested')->sole();
    $job = new RenderDubbingSubtitles($record->id, $event->id);
    expect(fn () => $job->handle(app(ProcessDubbingSubtitles::class), app(OutboxService::class)))->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('Retries exhausted'));
    expect($record->refresh()->status)->toBe('failed')->and($record->subtitle_status)->toBe('failed')->and(UsageCharge::sole()->status)->toBe('consumed');
    $this->postJson('/api/v1/dubbings/'.$record->id.'/retry')->assertOk()->assertJsonPath('subtitleStatus', 'pending');
    $this->postJson('/api/v1/dubbings/'.$record->id.'/retry')->assertOk();
    expect($event->refresh()->published_at)->toBeNull()->and(OutboxEvent::where('event_type', 'DubbingSubtitlesRequested')->count())->toBe(1);
    $job->handle(app(ProcessDubbingSubtitles::class), app(OutboxService::class));
    expect($record->refresh()->status)->toBe('complete')->and(CreditTransaction::where('kind', 'reserve')->count())->toBe(1)
        ->and(CreditTransaction::where('kind', 'consume')->count())->toBe(1)->and(CreditTransaction::where('kind', 'release')->count())->toBe(0);
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(1);
});

test('HeyGen subtitle jobs use its completed captions and the saved provider after configuration changes', function () {
    $record = Dubbing::factory()->create(['user_id' => $this->user->id, 'provider' => 'heygen', 'model' => 'precision',
        'target_language' => 'French', 'provider_project_id' => 'tr_test', 'provider_language_id' => 'tr_test', 'provider_completed_at' => now(),
        'status' => 'processing', 'subtitles_enabled' => true, 'subtitle_style' => 'classic', 'subtitle_status' => 'pending',
        'audio_storage_path' => 'dub/audio.flac', 'video_storage_path' => 'dub/clean.mp4']);
    config(['dubbing.heygen.key' => 'test-key']);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://api.heygen.com/v3/video-translations/tr_test' => Http::response(['data' => ['id' => 'tr_test', 'status' => 'completed',
        'output_language' => 'French', 'video_url' => 'https://files.heygen.ai/video.mp4', 'srt_caption_url' => 'https://files.heygen.ai/captions.srt']])]);
    $this->mock(DubbingSubtitleRendererInterface::class, function ($mock) {
        $mock->shouldReceive('render')->once()->withArgs(fn ($record, $captions) => $record->provider === 'heygen'
            && $captions === ['url' => 'https://files.heygen.ai/captions.srt'])
            ->andReturn(['subtitle_storage_path' => 'dub/captions.srt', 'captioned_video_storage_path' => 'dub/captioned.mp4']);
    });
    app(ProcessDubbingSubtitles::class)->handle($record->id);
    expect($record->refresh()->status)->toBe('complete');
    Http::assertSentCount(1);
});

function fakeSubtitleRenderProcess(?string $failure = null): void
{
    Process::fake(function ($process) use ($failure) {
        if (in_array('-show_entries', $process->command, true)) {
            return Process::result(output: json_encode(['format' => ['duration' => 60],
                'streams' => [['codec_type' => 'video', 'width' => 1280, 'height' => 720], ['codec_type' => 'audio']]]));
        }
        if ($failure !== null) {
            return Process::result(errorOutput: $failure, exitCode: 1);
        }
        expect(file_get_contents($process->path.'/input.mp4'))->toBe('dubbed-video');
        $ass = file_get_contents($process->path.'/captions.ass');
        expect($ass)->toContain('Ẹ káàbọ̀!', '0:00:01.00,0:00:03.00')->not->toContain('Welcome!', '{\\pos');
        file_put_contents($process->path.'/captioned.mp4', 'captioned-video');

        return Process::result();
    });
}

test('the rendering worker burns translated text with presets streams verified outputs and cleans temporary media', function () {
    $record = Dubbing::factory()->create(['subtitles_enabled' => true, 'subtitle_style' => 'boxed', 'video_storage_path' => 'dub/clean.mp4']);
    Storage::disk('r2')->put('dub/clean.mp4', 'dubbed-video');
    fakeSubtitleRenderProcess();
    $paths = app(R2DubbingSubtitleRenderer::class)->render(app(EloquentDubbingRepository::class)->find($record->id),
        ['segments' => [['start' => 1, 'end' => 3, 'text' => 'Ẹ káàbọ̀!']]]);
    expect(Storage::disk('r2')->get($paths['captioned_video_storage_path']))->toBe('captioned-video')
        ->and(Storage::disk('r2')->get($paths['subtitle_storage_path']))->toContain("00:00:01,000 --> 00:00:03,000\nẸ káàbọ̀!");
    Process::assertRan(function ($process) {
        if (! in_array('-vf', $process->command, true)) {
            return false;
        }
        expect(is_dir($process->path))->toBeFalse();

        return in_array('ass=filename=captions.ass,pad=ceil(iw/2)*2:ceil(ih/2)*2', $process->command, true)
            && in_array('libx264', $process->command, true) && in_array('copy', $process->command, true);
    });
});

test('HeyGen SRT downloads are read with timed text without trusting caption styling commands', function () {
    $record = Dubbing::factory()->create(['provider' => 'heygen', 'model' => 'precision', 'subtitles_enabled' => true,
        'subtitle_style' => 'contrast', 'video_storage_path' => 'dub/clean.mp4']);
    Storage::disk('r2')->put('dub/clean.mp4', 'dubbed-video');
    Http::swap(new Factory);
    Http::preventStrayRequests();
    config(['dubbing.heygen.download_hosts' => ['8.8.8.8']]);
    Http::fake(['https://8.8.8.8/*' => Http::response("1\n00:00:01,000 --> 00:00:03,000\n<b>Ẹ káàbọ̀!</b> {\\pos(0,0)}\n")]);
    fakeSubtitleRenderProcess();
    $paths = app(R2DubbingSubtitleRenderer::class)->render(app(EloquentDubbingRepository::class)->find($record->id), ['url' => 'https://8.8.8.8/captions.srt']);
    expect(Storage::disk('r2')->get($paths['subtitle_storage_path']))->toContain('Ẹ káàbọ̀!')->not->toContain('<b>');
});

test('a subtitle rendering error logs diagnostics preserves the clean video and publishes no captioned output', function () {
    $record = Dubbing::factory()->create(['subtitles_enabled' => true, 'video_storage_path' => 'dub/clean.mp4']);
    Storage::disk('r2')->put('dub/clean.mp4', 'dubbed-video');
    fakeSubtitleRenderProcess("No such filter: 'ass'");
    Log::spy();
    expect(fn () => app(R2DubbingSubtitleRenderer::class)->render(app(EloquentDubbingRepository::class)->find($record->id),
        ['segments' => [['start' => 1, 'end' => 3, 'text' => 'Ẹ káàbọ̀!']]]))->toThrow(RuntimeException::class, 'libass');
    Log::shouldHaveReceived('warning')->with('Dubbing subtitle render failed.', Mockery::on(fn ($data) => $data['dubbing_id'] === $record->id
        && $data['exit_code'] === 1 && str_contains($data['error'], 'No such filter')))->once();
    expect(Storage::disk('r2')->get('dub/clean.mp4'))->toBe('dubbed-video')
        ->and(Storage::disk('r2')->exists('dubbings/'.$record->id.'/captioned.mp4'))->toBeFalse();
});

test('subtitle output storage verifies uploads and rejects captions downloaded from untrusted hosts', function () {
    $record = Dubbing::factory()->create(['provider' => 'heygen', 'subtitles_enabled' => true, 'video_storage_path' => 'dub/clean.mp4']);
    Storage::disk('r2')->put('dub/clean.mp4', 'dubbed-video');
    fakeSubtitleRenderProcess();
    $entity = app(EloquentDubbingRepository::class)->find($record->id);
    expect(fn () => app(R2DubbingSubtitleRenderer::class)->render($entity, ['url' => 'https://attacker.example/captions.srt']))
        ->toThrow(RuntimeException::class, 'URL is invalid');
    Http::assertNothingSent();
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'dubbed-video');
    rewind($stream);
    Storage::shouldReceive('disk')->with('r2')->andReturn($disk = Mockery::mock());
    $disk->shouldReceive('readStream')->with('dub/clean.mp4')->once()->andReturn($stream);
    $disk->shouldReceive('put')->once()->andReturn(true);
    $disk->shouldReceive('exists')->once()->andReturn(true);
    $disk->shouldReceive('size')->once()->andReturn(1);
    expect(fn () => app(R2DubbingSubtitleRenderer::class)->render($entity, ['segments' => [['start' => 1, 'end' => 3, 'text' => 'Ẹ káàbọ̀!']]]))
        ->toThrow(RuntimeException::class, 'upload could not be verified');
});

test('installed FFmpeg renders visible subtitles into an actual MP4', function () {
    $binary = (string) config('dubbing.ffmpeg');
    $filters = Process::timeout(15)->run([$binary, '-hide_banner', '-filters']);
    if (! $filters->successful() || ! str_contains($filters->output(), ' ass ')) {
        $this->markTestSkipped('Install FFmpeg with libass to run the real rendering check.');
    }
    $source = tempnam(sys_get_temp_dir(), 'subtitle-source-');
    $output = tempnam(sys_get_temp_dir(), 'subtitle-output-');
    try {
        $made = Process::timeout(30)->run([$binary, '-nostdin', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=black:s=640x360:d=2',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', '-f', 'mp4', $source]);
        expect($made->successful())->toBeTrue();
        Storage::disk('r2')->put('dub/real.mp4', file_get_contents($source));
        $record = Dubbing::factory()->create(['subtitles_enabled' => true, 'subtitle_style' => 'classic', 'duration_ms' => 2000, 'video_storage_path' => 'dub/real.mp4']);
        $paths = app(R2DubbingSubtitleRenderer::class)->render(app(EloquentDubbingRepository::class)->find($record->id),
            ['segments' => [['start' => 0.2, 'end' => 1.8, 'text' => 'Ẹ káàbọ̀!']]]);
        file_put_contents($output, Storage::disk('r2')->get($paths['captioned_video_storage_path']));
        $frame = Process::timeout(15)->run([$binary, '-v', 'error', '-ss', '0.5', '-i', $output, '-frames:v', '1', '-vf', 'format=gray', '-f', 'rawvideo', '-']);
        expect($frame->successful())->toBeTrue()->and(strlen($frame->output()))->toBe(640 * 360)
            ->and(preg_match('/[\x80-\xFF]/', $frame->output()))->toBe(1);
    } finally {
        unlink($source);
        unlink($output);
    }
});
