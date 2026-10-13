<?php

namespace App\Infrastructure\AI\Dubbing\Google;

use App\Infrastructure\Observability\TimedMediaProcess;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleDubbingAudio
{
    public function stage(string $id, string $sourcePath, int $offsetMs = 0, int $durationMs = 1200000): string
    {
        return $this->workspace(function (string $directory) use ($id, $sourcePath, $offsetMs, $durationMs): string {
            $source = $directory.'/source';
            $this->copy($sourcePath, $source);
            $output = $directory.'/source.flac';
            $this->run(['-ss', (string) ($offsetMs / 1000), '-protocol_whitelist', 'file,pipe', '-i', $source,
                '-t', (string) ($durationMs / 1000), '-map', '0:a:0', '-vn',
                '-ac', '1', '-ar', '16000', '-c:a', 'flac', '-f', 'flac', $output], 600);
            $path = 'dubbings/'.$id.'/google/source.flac';
            $this->upload($output, $path, 'audio/flac');

            return $path;
        });
    }

    public function render(string $id, array $segments, int $durationMs): string
    {
        return $this->workspace(function (string $directory) use ($id, $segments, $durationMs): string {
            $raw = fopen($directory.'/timeline.pcm', 'wb');
            if ($raw === false) {
                throw new RuntimeException('Dubbing temporary storage is unavailable.');
            }
            $position = 0;
            try {
                foreach ($segments as $index => $segment) {
                    $start = (int) round($segment['start'] * 24000);
                    $end = min((int) round($segment['end'] * 24000), (int) round($durationMs * 24));
                    if ($start < $position || $end <= $start) {
                        throw new RuntimeException('Dubbing segments could not be aligned.');
                    }
                    $this->silence($raw, ($start - $position) * 2);
                    $input = $directory.'/speech.wav';
                    $this->copy('dubbings/'.$id.'/google/segments/'.$index.'.wav', $input);
                    $probe = TimedMediaProcess::run([(string) config('billing.ffprobe'), '-v', 'error',
                        '-protocol_whitelist', 'file,pipe', '-show_entries', 'format=duration', '-of', 'json', $input], 60);
                    $seconds = json_decode($probe->output(), true)['format']['duration'] ?? null;
                    if (! $probe->successful() || ! is_numeric($seconds) || ! is_finite((float) $seconds) || $seconds <= 0) {
                        throw new RuntimeException('The generated speech duration could not be measured.');
                    }
                    $window = ($end - $start) / 24000;
                    $ratio = max(1, (float) $seconds / $window);
                    $filters = ['aresample=24000'];
                    while ($ratio > 2) {
                        $filters[] = 'atempo=2';
                        $ratio /= 2;
                    }
                    $filters[] = 'atempo='.$ratio;
                    $filters[] = 'apad';
                    $filters[] = 'atrim=end_sample='.($end - $start);
                    $part = $directory.'/part.pcm';
                    $this->run(['-protocol_whitelist', 'file,pipe', '-i', $input, '-map', '0:a:0',
                        '-af', implode(',', $filters), '-ac', '1', '-ar', '24000', '-f', 's16le', $part], 120);
                    if (filesize($part) !== ($end - $start) * 2) {
                        throw new RuntimeException('The generated speech alignment could not be verified.');
                    }
                    $stream = fopen($part, 'rb');
                    try {
                        if ($stream === false || stream_copy_to_stream($stream, $raw) !== filesize($part)) {
                            throw new RuntimeException('The dubbed audio could not be assembled.');
                        }
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                    $position = $end;
                }
                $this->silence($raw, max(0, (int) round($durationMs * 24) - $position) * 2);
            } finally {
                fclose($raw);
            }
            $output = $directory.'/audio.flac';
            $this->run(['-f', 's16le', '-ar', '24000', '-ac', '1', '-i', $directory.'/timeline.pcm',
                '-c:a', 'flac', '-f', 'flac', $output], 600);
            $path = 'dubbings/'.$id.'/google/audio.flac';
            $this->upload($output, $path, 'audio/flac');

            return 'r2://'.$path;
        });
    }

    private function silence(mixed $stream, int $bytes): void
    {
        while ($bytes > 0) {
            $chunk = str_repeat("\0", min($bytes, 65536));
            if (fwrite($stream, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('The dubbed audio could not be assembled.');
            }
            $bytes -= strlen($chunk);
        }
    }

    private function copy(string $path, string $destination): void
    {
        $source = Storage::disk('r2')->readStream($path);
        $output = fopen($destination, 'wb');
        try {
            if (! is_resource($source) || $output === false
                || stream_copy_to_stream($source, $output, config('dubbing.max_bytes') + 1) !== Storage::disk('r2')->size($path)) {
                throw new RuntimeException('The dubbing source could not be read.');
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    private function upload(string $file, string $path, string $type): void
    {
        $stream = fopen($file, 'rb');
        try {
            if ($stream === false || filesize($file) < 1 || ! Storage::disk('r2')->put($path, $stream, ['ContentType' => $type])
                || Storage::disk('r2')->size($path) !== filesize($file)) {
                throw new RuntimeException('The Google dubbing upload could not be verified.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function run(array $arguments, int $timeout): void
    {
        if (! TimedMediaProcess::run([(string) config('dubbing.ffmpeg'), '-nostdin', '-y', '-v', 'error', ...$arguments], $timeout)->successful()) {
            throw new RuntimeException('Google dubbing media processing failed.');
        }
    }

    private function workspace(\Closure $work): string
    {
        $directory = sys_get_temp_dir().'/google-dubbing-'.Str::uuid();
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Dubbing temporary storage is unavailable.');
        }
        try {
            return $work($directory);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
