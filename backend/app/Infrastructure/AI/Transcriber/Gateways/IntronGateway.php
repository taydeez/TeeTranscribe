<?php

namespace App\Infrastructure\AI\Transcriber\Gateways;

use App\Domain\Transcriber\Contracts\TranscriberGatewayInterface;
use App\Infrastructure\AI\Transcriber\Intron\IntronClient;

class IntronGateway implements TranscriberGatewayInterface
{
    public function __construct(private readonly IntronClient $client) {}

    public function transcribe(
        string $audioUrl,
        string $languageCode,
        string $transcriptionId,
        ?float $duration = null,
        ?string $audioStoragePath = null,
        ?string $fileName = null,
        ?string $model = null,
    ): string {
        $path = parse_url($audioUrl, PHP_URL_PATH) ?: $audioUrl;
        $fileName ??= rawurldecode(basename($path)) ?: 'audio';

        return $this->client->upload($audioUrl, $fileName, $languageCode, $duration, $audioStoragePath);
    }

    public function provider(): string
    {
        return 'intron';
    }
}
