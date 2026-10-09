<?php

namespace App\Infrastructure\AI\Transcriber\OpenAI;

use App\Infrastructure\Billing\AudioDurationProbe;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class OpenAITranscriptionAudio
{
    public function __construct(private readonly AudioDurationProbe $probe) {}

    /** @return array{path: string, duration: float} */
    public function prepare(string $storagePath, ?float $expectedDuration = null): array
    {
        $disk = Storage::disk('r2');
        $size = $disk->size($storagePath);
        if ($size < 1 || $size > config('billing.media_max_bytes')) {
            throw new RuntimeException('The verified audio is empty or exceeds the supported size.');
        }
        $sourcePath = $this->temporary();
        $outputPath = $this->temporary();
        $completed = false;
        try {
            $source = $disk->readStream($storagePath);
            if (! is_resource($source)) {
                throw new RuntimeException('The verified audio could not be opened.');
            }
            $destination = fopen($sourcePath, 'wb');
            try {
                $copied = $destination === false ? false : stream_copy_to_stream($source, $destination, config('billing.media_max_bytes') + 1);
                if ($copied !== $size) {
                    throw new RuntimeException('The verified audio is incomplete.');
                }
            } finally {
                fclose($source);
                if (is_resource($destination)) {
                    fclose($destination);
                }
            }
            $duration = (float) $this->probe->inspect($sourcePath)['format']['duration'];
            if ($duration * 1000 > (int) config('transcriber.openai.max_duration_ms', 5400000)
                || ($expectedDuration !== null && abs($duration - $expectedDuration) > max(2, $expectedDuration * 0.01))) {
                throw new RuntimeException('The audio duration no longer matches its quote or exceeds the supported limit.');
            }
            $conversion = Process::timeout((int) config('transcriber.openai.conversion_timeout', 180))->run([
                (string) config('transcriber.openai.ffmpeg', 'ffmpeg'), '-nostdin', '-y', '-v', 'error',
                '-protocol_whitelist', 'file,pipe', '-format_whitelist', 'mp3,wav,mov,ogg,flac,matroska,webm,aac',
                '-i', $sourcePath, '-map', '0:a:0', '-vn', '-map_metadata', '-1',
                '-ac', '1', '-ar', '16000', '-c:a', 'libmp3lame', '-b:a', '32k', '-f', 'mp3', $outputPath,
            ]);
            clearstatcache(true, $outputPath);
            $bytes = filesize($outputPath);
            if (! $conversion->successful() || $bytes === false || $bytes < 1
                || $bytes > (int) config('transcriber.openai.max_upload_bytes', 24000000)) {
                throw new RuntimeException('The audio could not be prepared within the transcription upload limit.');
            }
            $outputDuration = (float) $this->probe->inspect($outputPath)['format']['duration'];
            if (abs($outputDuration - $duration) > 2) {
                throw new RuntimeException('The prepared audio is incomplete.');
            }
            $completed = true;

            return ['path' => $outputPath, 'duration' => $duration];
        } finally {
            unlink($sourcePath);
            if (! $completed) {
                unlink($outputPath);
            }
        }
    }

    private function temporary(): string
    {
        return tempnam(sys_get_temp_dir(), 'openai-audio-') ?: throw new RuntimeException('Temporary audio storage is unavailable.');
    }
}
