<?php

namespace App\Infrastructure\AI\Transcriber\Gateways;

use App\Domain\Transcriber\Contracts\TranscriberGatewayInterface;
use App\Infrastructure\AI\Transcriber\ElevenLabs\ElevenLabsTranscriptionClient;
use Illuminate\Support\Facades\Storage;

final class ElevenLabsGateway implements TranscriberGatewayInterface
{
    public function __construct(private readonly ElevenLabsTranscriptionClient $client) {}

    public function transcribe(string $audioUrl, string $languageCode, string $transcriptionId, ?float $duration = null, ?string $audioStoragePath = null, ?string $fileName = null, ?string $model = null): string
    {
        if ($audioStoragePath !== null) {
            $audioUrl = Storage::disk('r2')->temporaryUrl($audioStoragePath, now()->addHours(24));
        }

        return $this->client->transcribe($audioUrl, $languageCode, $transcriptionId, $model);
    }

    public function provider(): string
    {
        return 'elevenlabs';
    }
}
