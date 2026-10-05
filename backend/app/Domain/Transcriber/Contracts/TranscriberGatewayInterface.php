<?php

namespace App\Domain\Transcriber\Contracts;

interface TranscriberGatewayInterface
{
    public function transcribe(
        string $audioUrl,
        string $languageCode,
        string $transcriptionId,
        ?float $duration = null,
        ?string $audioStoragePath = null,
        ?string $fileName = null,
    ): string;

    public function provider(): string;
}
