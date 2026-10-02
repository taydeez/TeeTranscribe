<?php

namespace App\Infrastructure\AI\Transcriber\Intron;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IntronClient
{
    private function request(): PendingRequest
    {
        $key = config('transcriber.intron.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('INTRON_API_KEY is not configured.');
        }

        return Http::withToken($key)->acceptJson()->baseUrl(rtrim((string) config('transcriber.intron.endpoint'), '/'));
    }

    public function upload(string $audioUrl, string $fileName, string $languageCode): string
    {
        $response = $this->request()->asMultipart()->post('/file/v1/upload', [
            ['name' => 'audio_file_name', 'contents' => $fileName],
            ['name' => 'audio_file_blob', 'contents' => $audioUrl],
            ['name' => 'use_language_asr_input', 'contents' => $this->language($languageCode)],
            ['name' => 'use_diarization', 'contents' => (bool) config('transcriber.intron.diarization', true) ? 'TRUE' : 'FALSE'],
        ])->throw()->json();

        $id = data_get($response, 'data.file_id') ?? data_get($response, 'file_id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Intron did not return a file ID.');
        }

        return $id;
    }

    /** @return array<string, mixed> */
    public function status(string $fileId): array
    {
        return $this->request()->get('/file/v1/status/'.rawurlencode($fileId), [
            'get_structured_post_processing' => 'f',
        ])->throw()->json();
    }

    public function language(string $languageCode): string
    {
        return match (strtolower($languageCode)) {
            'en-ng', 'en-us', 'en-gb', 'en' => 'en',
            'pcm-ng' => 'pcm',
            default => strtolower($languageCode),
        };
    }
}
