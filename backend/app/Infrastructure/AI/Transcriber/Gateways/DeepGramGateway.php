<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Infrastructure\AI\Transcriber\Gateways;

use App\Infrastructure\AI\Transcriber\DeepGram\DeepGramClient;

class DeepGramGateway
{
    public function __construct(private readonly DeepGramClient $deepGramClient) {}

    public function transcribe(string $audioUrl, string $languageCode, string $transcriptionId): string
    {
        try {
            $request_id = $this->deepGramClient->transcribe($audioUrl, $languageCode, $transcriptionId);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($e->getMessage());
        }

        return $request_id;
    }
}
