<?php

use App\Domain\Dubbing\Entities\Dubbing;
use App\Infrastructure\AI\Dubbing\Google\GoogleDubbingAudio;
use App\Infrastructure\AI\Dubbing\R2DubbedAudioStorage;
use App\Infrastructure\AI\Dubbing\R2DubbingMedia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('r2');
    Http::preventStrayRequests();
    config(['observability.enabled' => false]);
});

test('Google dubbing assembles speech with silence gaps and the original media duration', function () {
    $id = (string) Str::ulid();
    Storage::disk('r2')->put('dubbings/'.$id.'/google/segments/0.wav', 'synthesized-speech');
    Process::fake(function ($process) {
        $command = $process->command;
        if (str_contains($command[0], 'ffprobe')) {
            return Process::result(output: json_encode(['format' => ['duration' => '2']]));
        }
        $output = end($command);
        if (str_ends_with($output, 'part.pcm')) {
            expect($command)->toContain('aresample=24000,atempo=2,apad,atrim=end_sample=24000');
            file_put_contents($output, str_repeat('s', 48000));
        } else {
            $raw = file_get_contents($command[array_search('-i', $command) + 1]);
            expect(strlen($raw))->toBe(144000)->and(substr($raw, 0, 48000))->toBe(str_repeat("\0", 48000))
                ->and(substr($raw, 48000, 48000))->toBe(str_repeat('s', 48000))
                ->and(substr($raw, 96000))->toBe(str_repeat("\0", 48000));
            file_put_contents($output, 'verified-flac');
        }

        return Process::result();
    });
    $url = app(GoogleDubbingAudio::class)->render($id, [['start' => 1, 'end' => 2, 'translation' => 'Bonjour']], 3000);
    expect($url)->toBe('r2://dubbings/'.$id.'/google/audio.flac');
    expect(Storage::disk('r2')->get('dubbings/'.$id.'/google/audio.flac'))->toBe('verified-flac');
});

test('Google source extraction seeks to the next chunk and excludes video streams', function () {
    $id = (string) Str::ulid();
    Storage::disk('r2')->put('audio/source.mp4', 'video-input');
    Process::fake(function ($process) {
        expect($process->command)->toContain('-ss', '1200', '-t', '60', '-vn', '16000');
        file_put_contents(end($process->command), 'chunk-audio');

        return Process::result();
    });
    $path = app(GoogleDubbingAudio::class)->stage($id, 'audio/source.mp4', 1200000, 60000);
    expect(Storage::disk('r2')->get($path))->toBe('chunk-audio');
});

test('Google audio and video exports read owned R2 audio directly without external downloads', function () {
    $id = (string) Str::ulid();
    Storage::disk('r2')->put('audio/source.mp4', 'video-input');
    Storage::disk('r2')->put('dubbings/'.$id.'/google/audio.flac', 'generated-audio');
    Process::fake(function ($process) {
        if (str_contains($process->command[0], 'ffprobe')) {
            $streams = str_starts_with(basename(str_replace('\\', '/', end($process->command))), 'aud')
                ? [['codec_type' => 'audio', 'codec_name' => 'flac']]
                : [['codec_type' => 'video', 'codec_name' => 'h264'], ['codec_type' => 'audio', 'codec_name' => 'flac']];

            return Process::result(output: json_encode(['format' => ['duration' => '3'],
                'streams' => $streams]));
        }
        file_put_contents(end($process->command), 'exported-media');

        return Process::result();
    });
    $record = new Dubbing($id, 1, 'Test', 'audio/source.mp4', 'en', 'fr', 3000, 'processing', provider: 'google');
    $url = 'r2://dubbings/'.$id.'/google/audio.flac';
    $video = app(R2DubbingMedia::class)->store($record, $url);
    $audio = app(R2DubbedAudioStorage::class)->store($record, $url);
    expect(Storage::disk('r2')->get($video['video_storage_path']))->toBe('exported-media');
    expect(Storage::disk('r2')->get($audio['audio_preview_storage_path']))->toBe('exported-media');
    Http::assertNothingSent();
});

test('native FFmpeg produces a playable Google dub with the original video duration', function () {
    $ffmpeg = (string) config('dubbing.ffmpeg');
    if (PHP_OS_FAMILY === 'Windows' && ! is_file($ffmpeg)) {
        $this->markTestSkipped('FFmpeg is not installed.');
    }
    if (! Process::run([$ffmpeg, '-version'])->successful()) {
        $this->markTestSkipped('FFmpeg is not installed.');
    }
    $id = (string) Str::ulid();
    $source = tempnam(sys_get_temp_dir(), 'google-dub-test-');
    try {
        $generated = Process::timeout(30)->run([$ffmpeg, '-nostdin', '-y', '-v', 'error', '-f', 'lavfi',
            '-i', 'color=c=black:s=160x120:r=10:d=3', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-t', '3', '-f', 'mp4', $source]);
        expect($generated->successful())->toBeTrue($generated->errorOutput());
        Storage::disk('r2')->put('audio/source.mp4', file_get_contents($source));
        $samples = str_repeat(pack('v', 1000), 48000);
        $wav = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16)
            .'data'.pack('V', strlen($samples)).$samples;
        Storage::disk('r2')->put('dubbings/'.$id.'/google/segments/0.wav', $wav);
        $url = app(GoogleDubbingAudio::class)->render($id, [['start' => 0.5, 'end' => 1.5, 'translation' => 'Bonjour']], 3000);
        $record = new Dubbing($id, 1, 'Native test', 'audio/source.mp4', 'en', 'fr', 3000, 'processing', provider: 'google');
        $paths = app(R2DubbingMedia::class)->store($record, $url);
        $probe = Process::run([(string) config('billing.ffprobe'), '-v', 'error', '-show_entries', 'format=duration:stream=codec_type',
            '-of', 'json', Storage::disk('r2')->path($paths['video_storage_path'])]);
        $result = json_decode($probe->output(), true);
        expect($probe->successful())->toBeTrue()->and((float) $result['format']['duration'])->toBeGreaterThanOrEqual(3)
            ->toBeLessThan(3.2)->and(array_column($result['streams'], 'codec_type'))->toContain('audio', 'video');
    } finally {
        unlink($source);
    }
});
