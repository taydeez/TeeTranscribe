<?php

namespace App\Infrastructure\AI\Transcriber\Gateways;

use App\Domain\Transcriber\Contracts\TranscriberGatewayInterface;
use App\Infrastructure\AI\Transcriber\OpenAI\OpenAITranscriptionAudio;
use App\Infrastructure\AI\Transcriber\OpenAI\OpenAITranscriptionClient;
use App\Infrastructure\AI\Transcriber\TranscriptionCompletion;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class OpenAIGateway implements TranscriberGatewayInterface
{
    public function __construct(
        private readonly OpenAITranscriptionAudio $audio,
        private readonly OpenAITranscriptionClient $client,
        private readonly TranscriptionCompletion $completion,
    ) {}

    public function transcribe(string $audioUrl, string $languageCode, string $transcriptionId, ?float $duration = null,
        ?string $audioStoragePath = null, ?string $fileName = null, ?string $model = null): string
    {
        if ($audioStoragePath === null || ! preg_match('/^[a-zA-Z0-9]{26}$/', $transcriptionId)) {
            throw new RuntimeException('A verified audio upload is required for transcription.');
        }
        $model ??= (string) config('transcriber.openai.model', 'gpt-4o-transcribe-diarize');
        $disk = Storage::disk('r2');
        $responsePath = 'transcription-inputs/'.$transcriptionId.'/openai-result.enc';
        $identity = ['model' => $model, 'language' => $languageCode, 'storage_path' => $audioStoragePath];
        $cached = $disk->exists($responsePath) ? json_decode(Crypt::decryptString($disk->get($responsePath)), true) : null;
        if (is_array($cached) && ($cached['identity'] ?? null) === $identity && is_array($cached['result'] ?? null)) {
            $result = $cached['result'];
            $measuredDuration = $cached['duration'];
        } else {
            $prepared = $this->audio->prepare($audioStoragePath, $duration);
            try {
                $result = $this->client->transcribe($prepared['path'], $languageCode, $transcriptionId, $model);
                $measuredDuration = $prepared['duration'];
                $cachedResult = Crypt::encryptString(json_encode(['identity' => $identity, 'duration' => $measuredDuration, 'result' => $result], JSON_THROW_ON_ERROR));
                if (! $disk->put($responsePath, $cachedResult, ['ContentType' => 'application/octet-stream']) || $disk->size($responsePath) !== strlen($cachedResult)) {
                    throw new RuntimeException('The transcription result could not be saved.');
                }
            } finally {
                unlink($prepared['path']);
            }
        }
        $this->completion->complete($transcriptionId, 'openai', $result['request_id'], $result['text'], $result['segments'], $measuredDuration);

        return $result['request_id'];
    }

    public function provider(): string
    {
        return 'openai';
    }
}
