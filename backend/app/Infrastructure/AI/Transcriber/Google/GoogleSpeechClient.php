<?php

namespace App\Infrastructure\AI\Transcriber\Google;

use App\Domain\Transcriber\Services\GoogleTranscriptionCapabilities;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GoogleSpeechClient
{
    public function __construct(private readonly GoogleSpeechCredentials $credentials) {}

    public function transcribe(string $uri, string $language, ?string $model = null): string
    {
        $project = config('transcriber.google.project');
        $location = config('transcriber.google.location', 'us');
        if (! is_string($project) || ! preg_match('/^[a-zA-Z0-9_-]+$/', $project) || ! in_array($location, ['us', 'eu'], true)) {
            throw new RuntimeException('Google Speech project and supported region must be configured.');
        }
        $features = [];
        if (config('transcriber.google.word_timestamps', true)) {
            $features['enableWordTimeOffsets'] = true;
        }
        if (config('transcriber.google.diarization', true) && GoogleTranscriptionCapabilities::speakers($language)) {
            $features['diarizationConfig'] = (object) [];
        }
        $response = $this->request($location)->post('/v2/projects/'.$project.'/locations/'.$location.'/recognizers/_:batchRecognize', [
            'config' => [
                'autoDecodingConfig' => (object) [], 'languageCodes' => [GoogleTranscriptionCapabilities::locale($language)],
                'model' => $model ?? config('transcriber.google.model', 'chirp_3'), 'features' => (object) $features,
            ],
            'files' => [['uri' => $uri]], 'recognitionOutputConfig' => ['inlineResponseConfig' => (object) []],
        ])->throw();
        $name = $response->json('name');
        if (! is_string($name) || ! preg_match('~^projects/[a-zA-Z0-9_-]+/locations/(us|eu)/operations/[a-zA-Z0-9_-]+$~', $name)) {
            throw new RuntimeException('Google Speech did not return a valid operation.');
        }

        return $name;
    }

    public function status(string $operation): array
    {
        if (! preg_match('~^projects/[a-zA-Z0-9_-]+/locations/(us|eu)/operations/[a-zA-Z0-9_-]+$~', $operation, $matches)) {
            throw new RuntimeException('Invalid Google Speech operation.');
        }

        return $this->request($matches[1])->get('/v2/'.$operation)->throw()->json();
    }

    private function request(string $location): PendingRequest
    {
        return Http::withToken($this->credentials->token())->acceptJson()->connectTimeout(10)->timeout(90)
            ->baseUrl('https://'.$location.'-speech.googleapis.com');
    }
}
