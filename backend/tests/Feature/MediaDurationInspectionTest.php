<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Infrastructure\Billing\AudioDurationProbe;
use App\Infrastructure\Billing\FfprobeMediaDurationInspector;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
beforeEach(function () {
    $this->user = User::factory()->create();
    config(['filesystems.disks.r2.endpoint' => 'https://storage.example.test', 'billing.ffprobe' => 'ffprobe', 'billing.media_max_bytes' => 1024]);
    Storage::fake('r2');
    Storage::disk('r2')->buildTemporaryUrlsUsing(fn ($path, $expires, $options) => 'https://storage.example.test/'.$path.'?signature=fresh');
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
});
function inspectionUpload(int $userId): UploadSession
{
    $id = (string) Str::ulid();
    $upload = UploadSession::create(['user_id' => $userId, 'client_key' => (string) Str::uuid(), 'filename' => 'interview.mp3', 'content_type' => 'audio/mpeg', 'size' => 5, 'fingerprint' => str_repeat('a', 64), 'storage_path' => 'audio/'.$id.'.mp3', 'part_size' => 5242880, 'status' => 'completed', 'expires_at' => now()->addDay()]);
    Storage::disk('r2')->put($upload->storage_path, 'audio');

    return $upload;
}
function inspectionOutput(): string
{
    return json_encode(['streams' => [['index' => 0]], 'format' => ['duration' => '90.001', 'format_name' => 'mp3']]);
}
test('owned R2 uploads are measured directly without downloading their entire content', function () {
    $upload = inspectionUpload($this->user->id);
    $disk = Mockery::mock(Storage::disk('r2'));
    $disk->shouldNotReceive('readStream');
    Storage::shouldReceive('disk')->with('r2')->andReturn($disk);
    Process::fake(['*' => Process::result(output: inspectionOutput())]);
    $result = app(FfprobeMediaDurationInspector::class)->measure($this->user->id, ['audio_storage_path' => $upload->storage_path, 'audio_url' => 'https://attacker.example/untrusted.mp3'], (string) Str::ulid());
    expect($result['duration_ms'])->toBe(90001)->and($result['file_name'])->toBe('interview.mp3')->and($result['audio_storage_path'])->toBe($upload->storage_path);
    Process::assertRan(fn ($process) => $process->timeout === 20 && end($process->command) === 'https://storage.example.test/'.$upload->storage_path.'?signature=fresh' && in_array('https,tls,tcp', $process->command, true));
    Http::assertNothingSent();
});
test('failed or incomplete remote metadata falls back to a local probe', function (string $output, int $exitCode) {
    $upload = inspectionUpload($this->user->id);
    Process::fake(['*' => Process::sequence()->push(Process::result(output: $output, exitCode: $exitCode))->push(Process::result(output: inspectionOutput()))]);
    $result = app(FfprobeMediaDurationInspector::class)->measure($this->user->id, ['audio_storage_path' => $upload->storage_path, 'audio_url' => 'https://storage.example.test/old-url'], (string) Str::ulid());
    expect($result['duration_ms'])->toBe(90001);
    Process::assertRan(fn ($process) => $process->timeout === 120 && in_array('file,pipe', $process->command, true) && ! str_starts_with(end($process->command), 'https://'));
})->with(['network failure' => ['', 1], 'missing duration' => ['{"streams":[{"index":0}],"format":{"format_name":"matroska,webm"}}', 0], 'no audio' => ['{"streams":[],"format":{"duration":"90"}}', 0]]);
test('another users upload is rejected before probing or reading R2', function () {
    $upload = inspectionUpload(User::factory()->create()->id);
    Process::fake();
    expect(fn () => app(FfprobeMediaDurationInspector::class)->measure($this->user->id, ['audio_storage_path' => $upload->storage_path, 'audio_url' => 'https://storage.example.test/audio'], (string) Str::ulid()))->toThrow(BillingException::class);
    Process::assertNothingRan();
});
test('pasted URLs remain downloaded and locally inspected before the verified copy goes to R2', function () {
    Http::fake(['https://93.184.216.34/audio.mp3' => Http::response('audio')]);
    Process::fake(['*' => Process::result(output: inspectionOutput())]);
    $quoteId = (string) Str::ulid();
    $result = app(FfprobeMediaDurationInspector::class)->measure($this->user->id, ['audio_url' => 'https://93.184.216.34/audio.mp3'], $quoteId);
    expect($result['duration_ms'])->toBe(90001)->and($result['audio_storage_path'])->toBe('billing-media/'.$quoteId.'.mp3');
    Storage::disk('r2')->assertExists($result['audio_storage_path']);
    Process::assertRan(fn ($process) => in_array('file,pipe', $process->command, true));
    Http::assertSentCount(1);
});

test('browser recordings without duration metadata are measured from local audio packets', function () {
    $upload = inspectionUpload($this->user->id);
    $packetPath = null;
    Process::fake(function ($process) use (&$packetPath) {
        if (in_array('-o', $process->command, true)) {
            $packetPath = $process->command[array_search('-o', $process->command, true) + 1];
            file_put_contents($packetPath, "pts_time=0.000|duration_time=0.020\npts_time=7.000|duration_time=0.020\n");

            return Process::result();
        }

        return Process::result(output: '{"streams":[{"index":0}],"format":{"format_name":"matroska,webm"}}');
    });
    $result = app(FfprobeMediaDurationInspector::class)->measure($this->user->id, ['audio_storage_path' => $upload->storage_path, 'audio_url' => 'https://storage.example.test/recording.webm', 'duration' => 999], (string) Str::ulid());
    expect($result['duration_ms'])->toBe(7020)->and(file_exists($packetPath))->toBeFalse();
    Process::assertRan(fn ($process) => in_array('-o', $process->command, true) && in_array('file,pipe', $process->command, true) && ! str_starts_with(end($process->command), 'https://'));
});

test('recordings without measurable audio packet timestamps are rejected and temporary output is removed', function () {
    $packetPath = null;
    Process::fake(function ($process) use (&$packetPath) {
        if (in_array('-o', $process->command, true)) {
            $packetPath = $process->command[array_search('-o', $process->command, true) + 1];
            file_put_contents($packetPath, "pts_time=N/A|duration_time=N/A\n");

            return Process::result();
        }

        return Process::result(output: '{"streams":[{"index":0}],"format":{"format_name":"matroska,webm"}}');
    });
    expect(fn () => app(AudioDurationProbe::class)->inspect('recording.webm'))->toThrow(BillingException::class, 'This media has no supported audio duration.');
    expect(file_exists($packetPath))->toBeFalse();
});
