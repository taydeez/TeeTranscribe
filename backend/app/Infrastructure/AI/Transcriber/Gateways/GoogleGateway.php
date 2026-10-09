<?php

namespace App\Infrastructure\AI\Transcriber\Gateways;

use App\Domain\Transcriber\Contracts\TranscriberGatewayInterface;
use App\Domain\Transcriber\Services\GoogleTranscriptionCapabilities;
use App\Infrastructure\AI\Transcriber\Google\GoogleSpeechAudioStorage;
use App\Infrastructure\AI\Transcriber\Google\GoogleSpeechClient;
use RuntimeException;

final class GoogleGateway implements TranscriberGatewayInterface
{
    public function __construct(private readonly GoogleSpeechClient $client, private readonly GoogleSpeechAudioStorage $audio) {}

    public function transcribe(string $audioUrl, string $languageCode, string $transcriptionId, ?float $duration = null, ?string $audioStoragePath = null, ?string $fileName = null, ?string $model = null): string
    {
        GoogleTranscriptionCapabilities::locale($languageCode);
        $maximum = config('transcriber.google.word_timestamps', true) ? 1200 : 3600;
        if ($duration !== null && $duration > $maximum) {
            throw new RuntimeException('This audio exceeds the configured speech model duration limit.');
        }
        if ($audioStoragePath === null) {
            throw new RuntimeException('Google Speech requires a verified audio file stored in R2.');
        }

        return $this->client->transcribe($this->audio->stage($transcriptionId, $audioStoragePath), $languageCode, $model);
    }

    public function provider(): string
    {
        return 'google';
    }
}
