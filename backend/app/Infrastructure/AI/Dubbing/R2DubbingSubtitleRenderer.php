<?php

namespace App\Infrastructure\AI\Dubbing;

use App\Domain\Dubbing\Contracts\DubbingSubtitleRendererInterface;
use App\Domain\Dubbing\Entities\Dubbing;
use App\Domain\Dubbing\Services\SubtitleDocument;
use App\Infrastructure\Billing\SafeAudioUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class R2DubbingSubtitleRenderer implements DubbingSubtitleRendererInterface
{
    public function __construct(private SafeAudioUrl $urls, private SubtitleDocument $document) {}

    public function render(Dubbing $record, array $subtitles): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dub-subtitles-'.Str::uuid();
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Temporary video storage is unavailable.');
        }
        try {
            $this->copyFromR2($record->videoStoragePath ?? '', $directory.'/input.mp4', config('dubbing.max_bytes'));
            $input = $this->probe($directory.'/input.mp4');
            $video = collect($input['streams'] ?? [])->firstWhere('codec_type', 'video');
            $duration = (float) ($input['format']['duration'] ?? 0);
            $width = (int) ($video['width'] ?? 0);
            $height = (int) ($video['height'] ?? 0);
            if (! is_finite($duration) || $duration <= 0 || $duration * 1000 > config('dubbing.max_duration_ms') * 2
                || $width < 1 || $height < 1 || $width > 8192 || $height > 8192) {
                throw new RuntimeException('The dubbed video could not be inspected for subtitles.');
            }
            if (isset($subtitles['storage_path'])) {
                $this->copyFromR2($subtitles['storage_path'], $directory.'/captions.srt', config('dubbing.subtitles.max_bytes'));
                $segments = $this->document->parseSrt(file_get_contents($directory.'/captions.srt'));
            } elseif (isset($subtitles['url'])) {
                $segments = $this->document->parseSrt($this->downloadCaption($subtitles['url'], $record->provider));
            } else {
                $segments = $subtitles['segments'] ?? [];
                if (strlen(json_encode($segments, JSON_THROW_ON_ERROR)) > config('dubbing.subtitles.max_bytes')) {
                    throw new RuntimeException('The subtitle transcript exceeds the size limit.');
                }
            }
            $cues = $this->document->cues($segments, $duration);
            $srt = $this->document->srt($cues);
            $ass = $this->document->ass($cues, $record->subtitleStyle ?? 'classic', $width, $height, config('dubbing.subtitles.font'));
            if (file_put_contents($directory.'/captions.srt', $srt) !== strlen($srt)
                || file_put_contents($directory.'/captions.ass', $ass) !== strlen($ass)) {
                throw new RuntimeException('The subtitle files could not be saved.');
            }
            $filter = 'ass=filename=captions.ass';
            if (filled(config('dubbing.subtitles.fonts_directory'))) {
                $this->copyFonts($directory);
                $filter .= ':fontsdir=fonts';
            }
            $filter .= ',pad=ceil(iw/2)*2:ceil(ih/2)*2';
            $result = Process::path($directory)->timeout(6600)->run([(string) config('dubbing.ffmpeg'), '-nostdin', '-y', '-v', 'error',
                '-protocol_whitelist', 'file,pipe', '-format_whitelist', 'mov', '-i', 'input.mp4', '-map', '0:v:0', '-map', '0:a:0',
                '-vf', $filter, '-c:v', 'libx264', '-preset', 'fast', '-crf', '20', '-pix_fmt', 'yuv420p',
                '-threads', (string) max(1, min(16, config('dubbing.subtitles.threads'))), '-c:a', 'copy',
                '-map_metadata', '-1', '-movflags', '+faststart', '-f', 'mp4', 'captioned.mp4']);
            if (! $result->successful()) {
                Log::warning('Dubbing subtitle render failed.', ['dubbing_id' => $record->id, 'exit_code' => $result->exitCode(),
                    'error' => substr(str_replace($directory, '[temporary media]', $result->errorOutput()), 0, 4000)]);
                throw new RuntimeException('Subtitles could not be rendered. Check FFmpeg libass support and installed fonts.');
            }
            $output = $this->probe($directory.'/captioned.mp4');
            $types = array_column($output['streams'] ?? [], 'codec_type');
            $seconds = $output['format']['duration'] ?? null;
            if (! in_array('video', $types, true) || ! in_array('audio', $types, true) || ! is_numeric($seconds)
                || ! is_finite((float) $seconds) || abs((float) $seconds - $duration) > 2) {
                throw new RuntimeException('The subtitled video could not be verified.');
            }
            $srtPath = 'dubbings/'.$record->id.'/captions.srt';
            $videoPath = 'dubbings/'.$record->id.'/captioned.mp4';
            $this->upload($directory.'/captions.srt', $srtPath, 'application/x-subrip; charset=utf-8');
            $this->upload($directory.'/captioned.mp4', $videoPath, 'video/mp4');

            return ['subtitle_storage_path' => $srtPath, 'captioned_video_storage_path' => $videoPath];
        } finally {
            foreach (['input.mp4', 'captions.srt', 'captions.ass', 'captioned.mp4'] as $name) {
                if (is_file($directory.'/'.$name)) {
                    unlink($directory.'/'.$name);
                }
            }
            if (is_dir($directory.'/fonts')) {
                foreach (glob($directory.'/fonts/*') as $font) {
                    unlink($font);
                }
                rmdir($directory.'/fonts');
            }
            rmdir($directory);
        }
    }

    private function downloadCaption(string $url, string $provider): string
    {
        $parts = parse_url($url);
        $hosts = $provider === 'heygen' ? config('dubbing.heygen.download_hosts') : config('dubbing.download_hosts');
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || ! in_array($parts['host'] ?? '', $hosts, true)) {
            throw new RuntimeException('The subtitle download URL is invalid.');
        }
        $response = Http::withOptions($this->urls->resolve($url) + ['allow_redirects' => false, 'stream' => true])
            ->connectTimeout(10)->timeout(90)->get($url);
        $body = $response->toPsrResponse()->getBody();
        try {
            if (! $response->successful()) {
                throw new RuntimeException('The subtitles could not be downloaded.');
            }
            $contents = '';
            while (! $body->eof()) {
                $contents .= $body->read(65536);
                if (strlen($contents) > config('dubbing.subtitles.max_bytes')) {
                    throw new RuntimeException('The subtitle file exceeds the size limit.');
                }
            }

            return $contents;
        } finally {
            $body->close();
        }
    }

    private function copyFromR2(string $source, string $destination, int $limit): void
    {
        $input = Storage::disk('r2')->readStream($source);
        $output = fopen($destination, 'wb');
        try {
            if (! is_resource($input) || ! is_resource($output)) {
                throw new RuntimeException('The dubbing files could not be opened.');
            }
            $bytes = stream_copy_to_stream($input, $output, $limit + 1);
            if ($bytes === false || $bytes < 1 || $bytes > $limit) {
                throw new RuntimeException('A dubbing file is empty or exceeds the size limit.');
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    private function probe(string $path): array
    {
        $result = Process::timeout(120)->run([(string) config('billing.ffprobe'), '-v', 'error', '-protocol_whitelist', 'file,pipe',
            '-format_whitelist', 'mov', '-show_entries', 'format=duration:stream=codec_type,width,height', '-of', 'json', $path]);
        $data = json_decode($result->output(), true);
        if (! $result->successful() || ! is_array($data)) {
            throw new RuntimeException('The dubbing video could not be inspected.');
        }

        return $data;
    }

    private function upload(string $local, string $path, string $type): void
    {
        $stream = fopen($local, 'rb');
        $bytes = filesize($local);
        $disk = Storage::disk('r2');
        try {
            if (! is_resource($stream) || $bytes < 1 || $bytes > config('dubbing.max_bytes')
                || ! $disk->put($path, $stream, ['ContentType' => $type]) || ! $disk->exists($path) || $disk->size($path) !== $bytes) {
                throw new RuntimeException('The subtitle output upload could not be verified.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function copyFonts(string $directory): void
    {
        $source = rtrim((string) config('dubbing.subtitles.fonts_directory'), '/\\');
        $files = array_merge(glob($source.'/*.ttf') ?: [], glob($source.'/*.otf') ?: []);
        if ($files === [] || count($files) > 100 || ! mkdir($directory.'/fonts', 0700)) {
            throw new RuntimeException('The subtitle fonts directory is unavailable.');
        }
        $bytes = 0;
        foreach ($files as $index => $file) {
            $bytes += filesize($file);
            if ($bytes > 200 * 1024 * 1024 || ! copy($file, $directory.'/fonts/'.$index.'.'.pathinfo($file, PATHINFO_EXTENSION))) {
                throw new RuntimeException('The subtitle fonts could not be loaded.');
            }
        }
    }
}
