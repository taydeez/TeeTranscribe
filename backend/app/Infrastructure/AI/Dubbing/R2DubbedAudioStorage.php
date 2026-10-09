<?php

namespace App\Infrastructure\AI\Dubbing;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Contracts\AudioDubbingMediaInterface;
use App\Domain\Dubbing\Entities\Dubbing;
use App\Infrastructure\Billing\SafeAudioUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final readonly class R2DubbedAudioStorage implements AudioDubbingMediaInterface
{
    private const FORMATS = 'mov,matroska,webm,flac,wav,mp3,ogg,aac';

    public function __construct(private SafeAudioUrl $urls) {}

    public function inspect(string $storagePath): array
    {
        $size = Storage::disk('r2')->size($storagePath);
        if ($size < 1 || $size > config('dubbing.max_bytes')) {
            throw new BillingException('Audio files must be smaller than 3 GiB.', 422);
        }
        $local = $this->temporary();
        try {
            $source = Storage::disk('r2')->readStream($storagePath);
            if (! is_resource($source)) {
                throw new RuntimeException('The audio could not be opened.');
            }
            $destination = fopen($local, 'wb');
            try {
                $bytes = $destination === false ? false : stream_copy_to_stream($source, $destination, config('dubbing.max_bytes') + 1);
                if ($bytes !== $size) {
                    throw new BillingException('The audio upload is incomplete.', 422);
                }
            } finally {
                fclose($source);
                if (is_resource($destination)) {
                    fclose($destination);
                }
            }
            $seconds = $this->duration($local, config('dubbing.max_duration_ms') / 1000);

            return ['size' => $size, 'duration_ms' => (int) ceil($seconds * 1000)];
        } finally {
            unlink($local);
        }
    }

    public function store(Dubbing $record, string $audioUrl): array
    {
        $download = $this->temporary();
        $flac = $this->temporary();
        $mp3 = $this->temporary();
        try {
            $this->download($audioUrl, $download);
            $duration = $this->duration($download, config('dubbing.max_duration_ms') / 500);
            $base = [(string) config('dubbing.ffmpeg'), '-nostdin', '-y', '-v', 'error', '-protocol_whitelist', 'file,pipe',
                '-format_whitelist', self::FORMATS, '-i', $download, '-map', '0:a:0', '-vn', '-map_metadata', '-1'];
            foreach ([[$flac, 'flac', 'flac'], [$mp3, 'libmp3lame', 'mp3']] as [$output, $codec, $format]) {
                $command = [...$base, '-c:a', $codec];
                if ($format === 'mp3') {
                    array_push($command, '-b:a', '192k');
                }
                $result = Process::timeout(600)->run([...$command, '-f', $format, $output]);
                if (! $result->successful()) {
                    Log::warning('Audio dubbing conversion failed.', ['dubbing_id' => $record->id, 'format' => $format,
                        'exit_code' => $result->exitCode(), 'error' => substr(str_replace([$download, $output],
                            ['[audio input]', '[audio output]'], $result->errorOutput()), 0, 4000)]);
                    throw new RuntimeException('The dubbed audio could not be prepared for download.');
                }
                if (abs($this->duration($output, config('dubbing.max_duration_ms') / 500) - $duration) > 2) {
                    throw new RuntimeException('The dubbed audio duration could not be verified.');
                }
            }
            $flacPath = 'dubbings/'.$record->id.'/audio.flac';
            $mp3Path = 'dubbings/'.$record->id.'/audio.mp3';
            $this->upload($flac, $flacPath, 'audio/flac');
            $this->upload($mp3, $mp3Path, 'audio/mpeg');

            return ['audio_storage_path' => $flacPath, 'audio_preview_storage_path' => $mp3Path];
        } finally {
            foreach ([$download, $flac, $mp3] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function duration(string $path, float $maximum): float
    {
        $result = Process::timeout(120)->run([(string) config('billing.ffprobe'), '-v', 'error', '-protocol_whitelist', 'file,pipe',
            '-format_whitelist', self::FORMATS, '-show_entries', 'format=duration:stream=codec_type:stream_disposition=attached_pic', '-of', 'json', $path]);
        $data = json_decode($result->output(), true);
        if (! $result->successful()) {
            Log::warning('Audio dubbing inspection failed.', ['exit_code' => $result->exitCode(),
                'error' => substr(str_replace($path, '[audio input]', $result->errorOutput()), 0, 2000)]);
        }
        $seconds = $data['format']['duration'] ?? null;
        $streams = $data['streams'] ?? [];
        $hasVideo = collect($streams)->contains(fn ($stream) => ($stream['codec_type'] ?? null) === 'video'
            && ! ($stream['disposition']['attached_pic'] ?? false));
        if (! $result->successful() || ! in_array('audio', array_column($streams, 'codec_type'), true) || $hasVideo
            || ! is_numeric($seconds) || ! is_finite((float) $seconds) || (float) $seconds <= 0 || (float) $seconds > $maximum) {
            throw new BillingException('Use a supported audio file, up to 180 minutes long.', 422);
        }

        return (float) $seconds;
    }

    private function download(string $url, string $path): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || ! in_array($parts['host'] ?? '', config('dubbing.download_hosts'), true)) {
            throw new RuntimeException('The dubbed audio download URL is invalid.');
        }
        $response = Http::withOptions($this->urls->resolve($url) + ['allow_redirects' => false, 'stream' => true])
            ->connectTimeout(10)->timeout(600)->get($url);
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
                if ($bytes > config('dubbing.max_bytes') || fwrite($destination, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('The dubbed audio could not be saved within the size limit.');
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
            if (! is_resource($stream) || $bytes < 1 || ! $disk->put($path, $stream, ['ContentType' => $type])
                || ! $disk->exists($path) || $disk->size($path) !== $bytes) {
                throw new RuntimeException('The dubbed audio upload could not be verified.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function temporary(): string
    {
        return tempnam(sys_get_temp_dir(), 'audio-dub-') ?: throw new RuntimeException('Temporary audio storage is unavailable.');
    }
}
