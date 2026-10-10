<?php

namespace App\Domain\Transcriber\Contracts;

use App\Domain\Transcriber\Entities\TranscriptTool;

interface TranscriptToolGatewayInterface
{
    /** @return array{configured: bool, model: string} */
    public function definition(string $operation = 'cleanup'): array;

    public function generate(TranscriptTool $record): array;
}
