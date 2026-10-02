<?php

namespace App\Domain\Transcriber\Contracts;

interface TranscriberGatewayInterface
{
    public function transcribe(string $audioUrl, string $languageCode, string $transcriptionId): string;

    public function provider(): string;
}
