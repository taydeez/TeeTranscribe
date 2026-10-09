<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingSubtitleRendererInterface;
use App\Domain\Dubbing\Services\DubbingService;
use App\Domain\Dubbing\Services\ProcessDubbing;
use App\Domain\Dubbing\Services\ProcessDubbingSubtitles;
use App\Infrastructure\AI\Dubbing\R2DubbingMedia;
use App\Infrastructure\AI\Dubbing\R2DubbingSubtitleRenderer;
use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentDubbingRepository;
use App\Jobs\RenderDubbingSubtitles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    config(['dubbing.key' => null, 'dubbing.heygen.key' => null, 'transcriber.deepgram.key' => 'test-key',
        'transcriber.deepgram.endpoint' => 'https://api.deepgram.com/v1/', 'translation.google.key' => 'test-key',
        'billing.free_credits' => '0', 'billing.rates.subtitles.deepgram.nova-2.credits' => '4']);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    app(CreditService::class)->purchase($this->user->id, 10000, 'test-funding');
    $this->upload = UploadSession::create(['user_id' => $this->user->id, 'client_key' => (string) Str::uuid(), 'filename' => 'English interview.mp4',
        'content_type' => 'video/mp4', 'size' => 1000, 'fingerprint' => str_repeat('c', 64), 'storage_path' => 'videos/'.Str::ulid().'.mp4',
        'part_size' => 16 * 1024 * 1024, 'expires_at' => now()->addDays(7), 'status' => 'completed']);
    Storage::disk('r2')->put($this->upload->storage_path, 'original-english-video');
    $this->media = $this->mock(DubbingMediaInterface::class, function ($mock) {
        $mock->shouldReceive('inspect')->andReturn(['size' => 1000, 'duration_ms' => 60000]);
        $mock->shouldReceive('sourceUrl')->andReturn('https://r2.example/original-english.mp4');
        $mock->shouldReceive('prepareOriginal')->andReturn(['video_storage_path' => 'videos/original.mp4', 'audio_storage_path' => 'videos/original.flac']);
        $mock->shouldNotReceive('store', 'storeVideo');
        $mock->shouldReceive('url')->andReturnUsing(fn ($path) => $path === null ? null : 'https://r2.example/'.$path);
    });
    $this->translationFails = false;
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'api.deepgram.com')) {
            return Http::response(['results' => ['channels' => [['detected_language' => 'en']], 'utterances' => [
                ['start' => 1.0, 'end' => 3.0, 'transcript' => 'Hello, welcome.'], ['start' => 5.0, 'end' => 8.0, 'transcript' => 'Thank you.']]]]);
        }
        if ($request->method() === 'GET') {
            return Http::response(['data' => ['languages' => [['language' => 'en', 'name' => 'English'], ['language' => 'es', 'name' => 'Spanish'],
                ['language' => 'yo', 'name' => 'Yoruba'], ['language' => 'ig', 'name' => 'Igbo'], ['language' => 'ha', 'name' => 'Hausa']]]]);
        }

        return $this->translationFails ? Http::response(['error' => ['status' => 'UNAVAILABLE']], 503)
            : Http::response(['data' => ['translations' => [['translatedText' => 'Hola, bienvenido.'], ['translatedText' => 'Gracias.']]]]);
    });
    $this->input = ['client_key' => (string) Str::uuid(), 'operation' => 'subtitles', 'video_storage_path' => $this->upload->storage_path,
        'source_language' => 'en', 'target_language' => 'es', 'subtitle_style' => 'boxed'];
});

function confirmSubtitlesOnly(object $test): Dubbing
{
    $quote = $test->postJson('/api/v1/dubbings/quotes', $test->input)->assertAccepted()->json();
    app(DubbingService::class)->measure($quote['id']);
    $test->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertAccepted()->assertJsonPath('operation', 'subtitles')
        ->assertJsonPath('subtitlesEnabled', true);

    return Dubbing::sole();
}

test('subtitle languages and pricing work independently of dubbing credentials', function () {
    $catalog = $this->getJson('/api/v1/dubbings/languages')->assertOk()->assertJsonPath('configured', false)
        ->assertJsonPath('subtitlesOnly.configured', true)->json('subtitlesOnly');
    expect(array_column($catalog['sourceLanguages'], 'code'))->toContain('en')->not->toContain('yo');
    expect(array_column($catalog['data'], 'code'))->toContain('es')->and(array_slice(array_column($catalog['data'], 'code'), 0, 3))->toBe(['yo', 'ig', 'ha']);
    $record = confirmSubtitlesOnly($this);
    expect(UsageCharge::sole()->credit_units)->toBe(400)->and($record->provider)->toBe('deepgram')->and($record->model)->toBe('nova-2');
});

test('English video receives timed Spanish subtitles without dubbing its original audio', function () {
    $record = confirmSubtitlesOnly($this);
    expect(app(ProcessDubbing::class)->handle($record->id))->toBeTrue();
    expect(app(ProcessDubbing::class)->handle($record->id))->toBeTrue();
    $record->refresh();
    expect($record->status)->toBe('processing')->and($record->source_subtitle_segments[0]['text'])->toBe('Hello, welcome.')
        ->and($record->translated_subtitle_segments)->toBe([
            ['start' => 1, 'end' => 3, 'text' => 'Hola, bienvenido.'], ['start' => 5, 'end' => 8, 'text' => 'Gracias.']]);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.deepgram.com') && $request['url'] === 'https://r2.example/original-english.mp4'
        && str_contains($request->url(), 'language=en') && ! str_contains($request->url(), 'callback='));
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'translation.googleapis.com')
        && $request['q'] === ['Hello, welcome.', 'Thank you.'] && $request['source'] === 'en' && $request['target'] === 'es');
    $this->mock(DubbingSubtitleRendererInterface::class, function ($mock) {
        $mock->shouldReceive('render')->once()->withArgs(fn ($item, $input) => $item->operation === 'subtitles'
            && $item->videoStoragePath === 'videos/original.mp4' && $input['segments'][1]['text'] === 'Gracias.')
            ->andReturn(['subtitle_storage_path' => 'videos/spanish.srt', 'captioned_video_storage_path' => 'videos/captioned.mp4']);
    });
    app(ProcessDubbingSubtitles::class)->handle($record->id);
    app(ProcessDubbingSubtitles::class)->handle($record->id);
    $this->getJson('/api/v1/dubbings/'.$record->id)->assertOk()->assertJsonPath('status', 'complete')
        ->assertJsonPath('videoDownloadUrl', 'https://r2.example/videos/captioned.mp4')
        ->assertJsonPath('cleanVideoDownloadUrl', 'https://r2.example/videos/original.mp4')->assertJsonPath('subtitleDownloadUrl', 'https://r2.example/videos/spanish.srt');
    expect(CreditTransaction::where('kind', 'consume')->count())->toBe(1)->and(UsageCharge::sole()->status)->toBe('consumed');
    expect(OutboxEvent::where('event_type', 'DubbingSubtitlesRequested')->count())->toBe(1);
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(2);
    expect(Storage::disk('r2')->get($this->upload->storage_path))->toBe('original-english-video');
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/dubbings/'.$record->id)->assertNotFound();
});

test('matching subtitle and spoken languages skip translation including detected English', function () {
    $this->input['source_language'] = null;
    $this->input['target_language'] = 'en';
    $record = confirmSubtitlesOnly($this);
    app(ProcessDubbing::class)->handle($record->id);
    expect($record->refresh()->translated_subtitle_segments)->toBe($record->source_subtitle_segments)
        ->and($record->detected_source_language)->toBe('en');
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'detect_language=true') && ! str_contains($request->url(), '&language='));
});

test('translation retry reuses the saved timed transcript and reserves credits once', function () {
    $record = confirmSubtitlesOnly($this);
    $this->translationFails = true;
    expect(fn () => app(ProcessDubbing::class)->handle($record->id))->toThrow(BillingException::class);
    expect($record->refresh()->source_subtitle_segments)->not->toBeNull()->and($record->translated_subtitle_segments)->toBeNull();
    $this->translationFails = false;
    app(ProcessDubbing::class)->handle($record->id);
    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'api.deepgram.com')))->toHaveCount(1);
    expect(CreditTransaction::where('kind', 'reserve')->count())->toBe(1)->and(CreditTransaction::where('kind', 'consume')->count())->toBe(1);
});

test('failed subtitle rendering retries reuse saved Spanish captions without another AI request or charge', function () {
    $record = confirmSubtitlesOnly($this);
    app(ProcessDubbing::class)->handle($record->id);
    $event = OutboxEvent::where('event_type', 'DubbingSubtitlesRequested')->sole();
    $job = new RenderDubbingSubtitles($record->id, $event->id);
    $job->failed(new RuntimeException('Renderer stopped'));
    expect($record->refresh()->status)->toBe('failed')->and(UsageCharge::sole()->status)->toBe('consumed');
    $this->postJson('/api/v1/dubbings/'.$record->id.'/retry')->assertOk()->assertJsonPath('status', 'processing');
    $this->postJson('/api/v1/dubbings/'.$record->id.'/retry')->assertOk();
    $this->mock(DubbingSubtitleRendererInterface::class, function ($mock) {
        $mock->shouldReceive('render')->once()->withArgs(fn ($item, $input) => $input['segments'][0]['text'] === 'Hola, bienvenido.')
            ->andReturn(['subtitle_storage_path' => 'spanish.srt', 'captioned_video_storage_path' => 'captioned.mp4']);
    });
    app(ProcessDubbingSubtitles::class)->handle($record->id);
    expect($record->refresh()->status)->toBe('complete')->and(OutboxEvent::where('event_type', 'DubbingSubtitlesRequested')->count())->toBe(1);
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(2);
    expect(CreditTransaction::where('kind', 'reserve')->count())->toBe(1)->and(CreditTransaction::where('kind', 'consume')->count())->toBe(1)
        ->and(CreditTransaction::where('kind', 'release')->count())->toBe(0);
});

test('exhausted subtitle preparation refunds credits without touching the original video', function () {
    $record = confirmSubtitlesOnly($this);
    $this->translationFails = true;
    expect(fn () => app(ProcessDubbing::class)->handle($record->id))->toThrow(BillingException::class);
    app(ProcessDubbing::class)->fail($record->id);
    app(ProcessDubbing::class)->fail($record->id);
    expect($record->refresh()->status)->toBe('failed')->and(UsageCharge::sole()->status)->toBe('released');
    expect(CreditTransaction::where('kind', 'release')->count())->toBe(1);
    expect(Storage::disk('r2')->get($this->upload->storage_path))->toBe('original-english-video');
});

test('actual subtitle rendering preserves the original audio track', function () {
    $ffmpeg = (string) config('dubbing.ffmpeg');
    $filters = Process::run([$ffmpeg, '-filters']);
    if (! $filters->successful() || ! str_contains($filters->output(), ' ass ')) {
        $this->markTestSkipped('FFmpeg with libass is unavailable.');
    }
    config(['dubbing.subtitles.fonts_directory' => null]);
    $source = tempnam(sys_get_temp_dir(), 'subtitle-source-');
    $captioned = tempnam(sys_get_temp_dir(), 'subtitle-output-');
    try {
        $generated = Process::timeout(30)->run([$ffmpeg, '-nostdin', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=black:s=640x360:d=2',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2', '-c:v', 'libx264', '-threads', '1', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-shortest', '-f', 'mp4', $source]);
        expect($generated->successful())->toBeTrue($generated->errorOutput());
        Storage::disk('r2')->put('english.mp4', file_get_contents($source));
        $model = Dubbing::factory()->create(['user_id' => $this->user->id, 'operation' => 'subtitles', 'provider' => 'deepgram', 'model' => 'nova-2',
            'source_storage_path' => 'english.mp4', 'source_language' => 'en', 'target_language' => 'es', 'duration_ms' => 2000,
            'subtitles_enabled' => true, 'subtitle_style' => 'classic', 'provider_completed_at' => now(),
            'translated_subtitle_segments' => [['start' => 0.2, 'end' => 1.8, 'text' => 'Hola, bienvenido.']]]);
        $records = app(EloquentDubbingRepository::class);
        $record = $records->find($model->id);
        $paths = app(R2DubbingMedia::class)->prepareOriginal($record);
        $record = $records->update($record->id, $paths);
        $rendered = app(R2DubbingSubtitleRenderer::class)->render($record, ['segments' => $record->translatedSubtitleSegments]);
        file_put_contents($captioned, Storage::disk('r2')->get($rendered['captioned_video_storage_path']));
        $hashArguments = ['-v', 'error', '-map', '0:a:0', '-c:a', 'pcm_s16le', '-f', 'hash', '-hash', 'sha256', '-'];
        $originalAudio = Process::timeout(20)->run([$ffmpeg, '-i', $source, ...$hashArguments]);
        $renderedAudio = Process::timeout(20)->run([$ffmpeg, '-i', $captioned, ...$hashArguments]);
        expect($originalAudio->successful())->toBeTrue()->and($renderedAudio->successful())->toBeTrue();
        expect(trim($renderedAudio->output()))->toBe(trim($originalAudio->output()));
        expect(Storage::disk('r2')->get($rendered['subtitle_storage_path']))->toContain('Hola, bienvenido.');
        Http::assertNothingSent();
    } finally {
        foreach ([$source, $captioned] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
});
