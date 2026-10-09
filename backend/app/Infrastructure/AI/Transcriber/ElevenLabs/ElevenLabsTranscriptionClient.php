<?php

namespace App\Infrastructure\AI\Transcriber\ElevenLabs;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ElevenLabsTranscriptionClient
{
    public function transcribe(string $url, string $language, string $id, ?string $model = null): string
    {
        $key = config('transcriber.elevenlabs.key');
        $webhook = config('transcriber.elevenlabs.webhook_id');
        if (blank($key) || blank($webhook) || blank(config('transcriber.elevenlabs.webhook_secret'))) {
            throw new RuntimeException('ElevenLabs transcription credentials and webhook must be configured.');
        }
        $language = explode('-', $language)[0];
        $language = ['yo' => 'yor', 'ig' => 'ibo', 'ha' => 'hau'][$language] ?? $language;
        $response = Http::withHeaders(['xi-api-key' => $key])->acceptJson()->asMultipart()
            ->connectTimeout(10)->timeout(120)->post('https://api.elevenlabs.io/v1/speech-to-text', [
                'model_id' => $model ?? config('transcriber.elevenlabs.model', 'scribe_v2'),
                'source_url' => $url, 'language_code' => $language,
                'diarize' => config('transcriber.elevenlabs.diarization', true) ? 'true' : 'false',
                'tag_audio_events' => 'false', 'timestamps_granularity' => 'word',
                'webhook' => 'true', 'webhook_id' => $webhook,
                'webhook_metadata' => json_encode(['transcription_id' => $id], JSON_THROW_ON_ERROR),
            ])->throw();
        $requestId = $response->json('request_id');
        if (! is_string($requestId) || $requestId === '') {
            throw new RuntimeException('ElevenLabs did not return a transcription request ID.');
        }

        return $requestId;
    }
}
