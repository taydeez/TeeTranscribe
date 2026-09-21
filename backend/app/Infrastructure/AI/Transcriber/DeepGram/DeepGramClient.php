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

    public function transcribe(string $audioUrl, string $languageCode, string $transcription_id): string
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

            Log::info('Deepgram API request initiated-----------'."$audioUrl"."$callbackUrl");

            $response = Http::withToken(
                config('transcriber.deepgram.key'),
                'Token'
            )
                ->acceptJson()
                ->withQueryParameters([
                    'model' => $this->model,
                    'language' => $languageCode,
                    'punctuate' => $this->punctuate,
                    'smart_format' => 'true',
                    'callback' => $callbackUrl,
                ])
                ->timeout(120)
                ->post(rtrim($this->endpoint, '/').'/listen', [
                    'url' => $audioUrl,
                ])->throw();
        } catch (\Exception $e) {
            throw new RuntimeException('Deepgram API request failed----------------------->'.$e->getMessage());
        }
        Log::info('Deepgram API request successful', ['response' => $response->json()]);

        return $response->json('request_id');
    }
}
