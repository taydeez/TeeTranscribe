<?php

namespace App\Infrastructure\AI\Transcriber\Intron;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class IntronClient
{
    private function request(): PendingRequest
    {
        $key = config('transcriber.intron.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('INTRON_API_KEY is not configured.');
        }

        return Http::withToken($key)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->baseUrl(rtrim((string) config('transcriber.intron.endpoint'), '/'));
    }

    public function upload(
        string $audioUrl,
        string $fileName,
        string $languageCode,
        ?float $duration = null,
        ?string $audioStoragePath = null,
    ): string {
        $audio = tmpfile();
        if ($audio === false) {
            throw new RuntimeException('Could not create a temporary file for the Intron upload.');
        }

        try {
            $contentType = $this->spoolAudio($audio, $audioUrl, $audioStoragePath);

            rewind($audio);

            $response = $this->request()
                ->timeout((int) config('transcriber.intron.upload_timeout', 300))
                ->attach(
                    'audio_file_blob',
                    $audio,
                    $this->safeFileName($fileName),
                    ['Content-Type' => $contentType],
                )
                ->post('/file/v1/upload', [
                    'audio_file_name' => $fileName,
                    'use_language_asr_input' => $this->language($languageCode),
                    'use_diarization' => $this->shouldDiarize($duration) ? 'TRUE' : 'FALSE',
                ]);

            Log::info('Intron upload response', [
                'http_status' => $response->status(),
                'file_id' => $response->json('data.file_id') ?? $response->json('file_id'),
                'status' => $response->json('status'),
            ]);

            $response = $response->throw()->json();
        } finally {
            if (is_resource($audio)) {
                fclose($audio);
            }
        }

        $id = data_get($response, 'data.file_id') ?? data_get($response, 'file_id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Intron did not return a file ID.');
        }

        return $id;
    }

    /** @param resource $destination */
    private function spoolAudio($destination, string $audioUrl, ?string $audioStoragePath): string
    {
        if ($audioStoragePath === null) {
            $download = Http::accept('*/*')
                ->connectTimeout(10)
                ->timeout((int) config('transcriber.intron.download_timeout', 300))
                ->sink($destination)
                ->get($audioUrl)
                ->throw();

            return $download->header('Content-Type') ?: 'application/octet-stream';
        }

        $source = Storage::disk('r2')->readStream($audioStoragePath);
        if ($source === false) {
            throw new RuntimeException('Could not read the audio file from R2.');
        }

        try {
            if (stream_copy_to_stream($source, $destination) === false) {
                throw new RuntimeException('Could not copy the R2 audio file for Intron.');
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }

        return $this->contentType($audioStoragePath);
    }

    private function contentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a', 'mp4' => 'audio/mp4',
            'ogg', 'oga' => 'audio/ogg',
            'flac' => 'audio/flac',
            'webm' => 'audio/webm',
            'aac' => 'audio/aac',
            default => 'application/octet-stream',
        };
    }

    private function safeFileName(string $fileName): string
    {
        $fileName = basename(str_replace('\\', '/', trim($fileName)));

        return $fileName !== '' ? $fileName : 'audio';
    }

    private function shouldDiarize(?float $duration): bool
    {
        return (bool) config('transcriber.intron.diarization', true)
            && $duration !== null
            && $duration >= (float) config('transcriber.intron.diarization_min_duration', 7200);
    }

    /** @return array<string, mixed> */
    public function status(string $fileId): array
    {
        $response = $this->request()->get('/file/v1/status/'.rawurlencode($fileId), [
            'get_structured_post_processing' => 'f',
        ]);

        Log::info('Intron status response', [
            'file_id' => $fileId,
            'http_status' => $response->status(),
            'processing_status' => $response->json('data.processing_status'),
            'duration_seconds' => $response->json('data.processed_audio_duration_in_seconds'),
        ]);

        $response = $response->throw()->json();

        if (! is_array($response)) {
            throw new RuntimeException('Intron returned an invalid status response.');
        }

        return $response;
    }

    public function language(string $languageCode): string
    {
        return explode('-', strtolower(trim($languageCode)), 2)[0];
    }
}
