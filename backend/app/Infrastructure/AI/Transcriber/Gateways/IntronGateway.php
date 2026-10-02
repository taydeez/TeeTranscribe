<?php

namespace App\Infrastructure\AI\Transcriber\Gateways;

use App\Domain\Transcriber\Contracts\TranscriberGatewayInterface;
use App\Infrastructure\AI\Transcriber\Intron\IntronClient;
use App\Jobs\PollIntronTranscription;

class IntronGateway implements TranscriberGatewayInterface
{
    public function __construct(private readonly IntronClient $client) {}

    public function transcribe(string $audioUrl, string $languageCode, string $transcriptionId): string
    {
        $path = parse_url($audioUrl, PHP_URL_PATH) ?: $audioUrl;
        $fileName = rawurldecode(basename($path)) ?: 'audio';
        $id = $this->client->upload($audioUrl, $fileName, $languageCode);

        PollIntronTranscription::dispatch($transcriptionId)->delay(now()->addSeconds(5));

        return $id;
    }

    public function provider(): string
    {
        return 'intron';
    }
}
