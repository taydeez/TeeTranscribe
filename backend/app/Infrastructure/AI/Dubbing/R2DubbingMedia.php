<?php

namespace App\Infrastructure\AI\Dubbing;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Entities\Dubbing;
use App\Infrastructure\Billing\SafeAudioUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final readonly class R2DubbingMedia implements DubbingMediaInterface
{
    public function __construct(private SafeAudioUrl $urls) {}

    public function inspect(string $storagePath): array
    {
        $size = Storage::disk('r2')->size($storagePath);
        if ($size < 1 || $size > config('dubbing.max_bytes')) {
            throw new BillingException('Videos must be smaller than 3 GiB.', 422);
        }
        $local = $this->temporary();
        try {
            $this->copySource($storagePath, $local);
            $data = $this->probe($local);
            $types = array_column($data['streams'] ?? [], 'codec_type');
            $seconds = $data['format']['duration'] ?? null;
            if (! in_array('video', $types, true) || ! in_array('audio', $types, true) || ! is_numeric($seconds)
                || ! is_finite((float) $seconds) || (float) $seconds <= 0 || (float) $seconds * 1000 > config('dubbing.max_duration_ms')) {
                throw new BillingException('Use a video with an audio track, up to 180 minutes long.', 422);
            }

            return ['size' => $size, 'duration_ms' => (int) ceil((float) $seconds * 1000)];
        } finally {
            unlink($local);
        }
    }

    public function sourceUrl(string $storagePath): string
    {
        return Storage::disk('r2')->temporaryUrl($storagePath, now()->addHours(48));
    }

    public function store(Dubbing $record, string $audioUrl): array
    {
        $source = $this->temporary();
        $audio = $this->temporary();
        $flac = $this->temporary();
        $video = $this->temporary();
        try {
            $this->copySource($record->sourceStoragePath, $source);
            $this->download($audioUrl, $audio);
            $input = $this->probe($source);
            $codec = collect($input['streams'] ?? [])->firstWhere('codec_type', 'video')['codec_name'] ?? null;
            $base = [(string) config('dubbing.ffmpeg'), '-nostdin', '-y', '-v', 'error'];
            $converted = Process::timeout(600)->run([...$base, '-protocol_whitelist', 'file,pipe', '-i', $audio, '-map', '0:a:0', '-c:a', 'flac', '-f', 'flac', $flac]);
            if (! $converted->successful()) {
                Log::warning('Dubbing audio conversion failed.', [
                    'dubbing_id' => $record->id,
                    'executable' => config('dubbing.ffmpeg'),
                    'exit_code' => $converted->exitCode(),
                    'error' => substr(str_replace([$audio, $flac], ['[audio input]', '[audio output]'], $converted->errorOutput()), 0, 4000),
                ]);
                throw new RuntimeException('The dubbed audio could not be decoded.');
            }
            $command = [...$base, '-protocol_whitelist', 'file,pipe', '-format_whitelist', 'mov,matroska,webm', '-i', $source,
                '-protocol_whitelist', 'file,pipe', '-i', $flac, '-map', '0:v:0', '-map', '1:a:0', '-c:v', $codec === 'h264' ? 'copy' : 'libx264'];
            if ($codec !== 'h264') {
                array_push($command, '-preset', 'fast', '-crf', '20', '-pix_fmt', 'yuv420p');
            }
            array_push($command, '-c:a', 'aac', '-b:a', '192k', '-af', 'apad', '-t', (string) ($record->durationMs / 1000),
                '-map_metadata', '-1', '-movflags', '+faststart', '-f', 'mp4', $video);
            if (! Process::timeout(2400)->run($command)->successful()) {
                throw new RuntimeException('The dubbed video could not be generated.');
            }
            $output = $this->probe($video);
            $types = array_column($output['streams'] ?? [], 'codec_type');
            if (! in_array('video', $types, true) || ! in_array('audio', $types, true)
                || abs((float) ($output['format']['duration'] ?? 0) - $record->durationMs / 1000) > 2) {
                throw new RuntimeException('The dubbed video could not be verified.');
            }
            $audioPath = 'dubbings/'.$record->id.'/audio.flac';
            $videoPath = 'dubbings/'.$record->id.'/video.mp4';
            $this->upload($flac, $audioPath, 'audio/flac');
            $this->upload($video, $videoPath, 'video/mp4');

            return ['audio_storage_path' => $audioPath, 'video_storage_path' => $videoPath];
        } finally {
            foreach ([$source, $audio, $flac, $video] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function storeVideo(Dubbing $record, string $videoUrl): array
    {
        return $this->prepareVideo($record, $videoUrl);
    }

    public function prepareOriginal(Dubbing $record): array
    {
        return $this->prepareVideo($record, null);
    }

    private function prepareVideo(Dubbing $record, ?string $videoUrl): array
    {
        $download = $this->temporary();
        $video = $this->temporary();
        $flac = $this->temporary();
        try {
            if ($videoUrl === null) {
                $this->copySource($record->sourceStoragePath, $download);
            } else {
                $this->download($videoUrl, $download, 'heygen');
            }
            $input = $this->probe($download);
            $types = array_column($input['streams'] ?? [], 'codec_type');
            $seconds = $input['format']['duration'] ?? null;
            if (! in_array('video', $types, true) || ! in_array('audio', $types, true) || ! is_numeric($seconds)
                || ! is_finite((float) $seconds) || (float) $seconds <= 0 || (float) $seconds * 1000 > config('dubbing.max_duration_ms') * 2) {
                throw new RuntimeException('The dubbed video could not be verified.');
            }
            $base = [(string) config('dubbing.ffmpeg'), '-nostdin', '-y', '-v', 'error', '-protocol_whitelist', 'file,pipe',
                '-format_whitelist', 'mov,matroska,webm', '-i', $download];
            if (! Process::timeout(600)->run([...$base, '-map', '0:a:0', '-c:a', 'flac', '-f', 'flac', $flac])->successful()) {
                throw new RuntimeException('The dubbed audio could not be decoded.');
            }
            $codec = collect($input['streams'])->firstWhere('codec_type', 'video')['codec_name'] ?? null;
            $command = [...$base, '-map', '0:v:0', '-map', '0:a:0', '-c:v', $codec === 'h264' ? 'copy' : 'libx264'];
            if ($codec !== 'h264') {
                array_push($command, '-preset', 'fast', '-crf', '20', '-pix_fmt', 'yuv420p');
            }
            $audioCodec = collect($input['streams'])->firstWhere('codec_type', 'audio')['codec_name'] ?? null;
            array_push($command, '-c:a', $videoUrl === null && $audioCodec === 'aac' ? 'copy' : 'aac');
            if ($videoUrl !== null || $audioCodec !== 'aac') {
                array_push($command, '-b:a', '192k');
            }
            array_push($command, '-map_metadata', '-1', '-movflags', '+faststart', '-f', 'mp4', $video);
            if (! Process::timeout(2400)->run($command)->successful()) {
                throw new RuntimeException('The dubbed video could not be prepared.');
            }
            $output = $this->probe($video);
            $audio = $this->probe($flac);
            $outputTypes = array_column($output['streams'] ?? [], 'codec_type');
            if (! in_array('video', $outputTypes, true) || ! in_array('audio', $outputTypes, true)
                || ! in_array('audio', array_column($audio['streams'] ?? [], 'codec_type'), true)
                || abs((float) ($output['format']['duration'] ?? 0) - (float) $seconds) > 2
                || abs((float) ($audio['format']['duration'] ?? 0) - (float) $seconds) > 2) {
                throw new RuntimeException('The dubbing outputs could not be verified.');
            }
            $audioPath = 'dubbings/'.$record->id.'/audio.flac';
            $videoPath = 'dubbings/'.$record->id.'/video.mp4';
            $this->upload($flac, $audioPath, 'audio/flac');
            $this->upload($video, $videoPath, 'video/mp4');

            return ['audio_storage_path' => $audioPath, 'video_storage_path' => $videoPath];
        } finally {
            foreach ([$download, $video, $flac] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function url(?string $storagePath, string $filename, bool $download = false): ?string
    {
        if ($storagePath === null) {
            return null;
        }
        $filename = preg_replace('/[^\pL\pN ._-]/u', '', $filename) ?: 'dubbed-video.mp4';
        try {
            return Storage::disk('r2')->temporaryUrl($storagePath, now()->addMinutes(30), [
                'ResponseContentDisposition' => ($download ? 'attachment' : 'inline').'; filename="'.$filename.'"']);
        } catch (\Throwable) {
            return null;
        }
    }

    private function temporary(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dubbing-');
        if ($path === false) {
            throw new RuntimeException('Temporary media storage is unavailable.');
        }

        return $path;
    }

    private function probe(string $path): array
    {
        $result = Process::timeout(120)->run([(string) config('billing.ffprobe'), '-v', 'error', '-protocol_whitelist', 'file,pipe',
            '-format_whitelist', 'mov,matroska,webm,flac,wav', '-show_entries', 'format=duration:stream=codec_type,codec_name', '-of', 'json', $path]);
        $data = json_decode($result->output(), true);
        if (! $result->successful() || ! is_array($data)) {
            throw new BillingException('The video could not be inspected.', 422);
        }

        return $data;
    }

    private function copySource(string $storagePath, string $path): void
    {
        $stream = Storage::disk('r2')->readStream($storagePath);
        if (! is_resource($stream)) {
            throw new RuntimeException('The video could not be opened.');
        }
        $destination = fopen($path, 'wb');
        try {
            if ($destination === false) {
                throw new RuntimeException('Temporary media storage is unavailable.');
            }
            $bytes = stream_copy_to_stream($stream, $destination, config('dubbing.max_bytes') + 1);
            if ($bytes === false || $bytes < 1 || $bytes > config('dubbing.max_bytes')) {
                throw new BillingException('Videos must be smaller than 3 GiB.', 422);
            }
        } finally {
            fclose($stream);
            if (is_resource($destination)) {
                fclose($destination);
            }
        }
    }

    private function download(string $url, string $path, string $provider = 'elevenlabs'): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || ! in_array($parts['host'] ?? '', config($provider === 'heygen' ? 'dubbing.heygen.download_hosts' : 'dubbing.download_hosts'), true)) {
            throw new RuntimeException('The dubbed audio download URL is invalid.');
        }
        $response = Http::withOptions($this->urls->resolve($url) + ['allow_redirects' => false, 'stream' => true])->connectTimeout(10)->timeout(600)->get($url);
        $body = $response->toPsrResponse()->getBody();
        $destination = fopen($path, 'wb');
        try {
            if (! $response->successful() || $destination === false) {
                throw new RuntimeException('The dubbed audio could not be downloaded.');
            }
            $bytes = 0;
            while (! $body->eof()) {
                $chunk = $body->read(1024 * 1024);
                $bytes += strlen($chunk);
                if ($bytes > config('dubbing.max_bytes')) {
                    throw new RuntimeException('The dubbed audio exceeds the download limit.');
                }
                if (fwrite($destination, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('The dubbed audio could not be saved.');
                }
            }
            if ($bytes < 1) {
                throw new RuntimeException('The dubbed audio is empty.');
            }
        } finally {
            $body->close();
            if (is_resource($destination)) {
                fclose($destination);
            }
        }
    }

    private function upload(string $local, string $path, string $type): void
    {
        $stream = fopen($local, 'rb');
        $bytes = filesize($local);
        $disk = Storage::disk('r2');
        try {
            if (! is_resource($stream) || $bytes < 1 || ! $disk->put($path, $stream, ['ContentType' => $type]) || ! $disk->exists($path) || $disk->size($path) !== $bytes) {
                throw new RuntimeException('The dubbing output upload could not be verified.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
