<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Infrastructure\AI\Transcriber\DeepGram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use RuntimeException;

final class DeepGramClient
{
    private string $endpoint;

    private string $model;

    private string $punctuate;

    public function __construct()
    {
        $this->endpoint = rtrim(config('transcriber.deepgram.endpoint', 'https://api.deepgram.com/v1/'), '/');
        $this->punctuate = rtrim(config('transcriber.deepgram.punctuate', 'true'));
        $this->model = rtrim(config('transcriber.deepgram.model', 'nova-2'));
    }

    public function transcribe(string $audioUrl, string $languageCode, string $transcription_id, ?string $model = null): string
    {

        if ((! filter_var($audioUrl, FILTER_VALIDATE_URL)) || $languageCode === '') {
            throw new RuntimeException('Invalid audio URL or language code');
        }

        try {
            $relativeUrl = URL::temporarySignedRoute(
                'deepgram.callback',
                now()->addHours(24),
                ['transcription' => $transcription_id],
                absolute: false
            );

            $callbackUrl = rtrim(config('app.url'), '/').$relativeUrl;

            Log::info('Deepgram API request initiated', ['transcription_id' => $transcription_id]);

            $response = Http::withToken(
                config('transcriber.deepgram.key'),
                'Token'
            )
                ->acceptJson()
                ->withQueryParameters([
                    'model' => $model ?? $this->model,
                    'language' => $languageCode,
                    'punctuate' => $this->punctuate,
                    'smart_format' => 'true',
                    'utterances' => 'true',
                    'diarize_model' => 'latest',
                    'callback' => $callbackUrl,
                ])
                ->timeout(120)
                ->post(rtrim($this->endpoint, '/').'/listen', [
                    'url' => $audioUrl,
                ])->throw();
        } catch (\Exception $e) {
            Log::warning('Deepgram API request failed', ['transcription_id' => $transcription_id, 'exception_type' => $e::class]);
            throw new RuntimeException('Deepgram API request failed.');
        }
        Log::info('Deepgram API request successful', ['transcription_id' => $transcription_id, 'http_status' => $response->status(), 'request_id' => $response->json('request_id')]);

        return $response->json('request_id');
    }
}
