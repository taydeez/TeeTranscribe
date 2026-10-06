<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Infrastructure\AI\Transcriber\Gateways;

use App\Domain\Transcriber\Contracts\TranscriberGatewayInterface;
use App\Infrastructure\AI\Transcriber\DeepGram\DeepGramClient;
use Illuminate\Support\Facades\Storage;

class DeepGramGateway implements TranscriberGatewayInterface
{
    public function __construct(private readonly DeepGramClient $deepGramClient) {}

    public function transcribe(
        string $audioUrl,
        string $languageCode,
        string $transcriptionId,
        ?float $duration = null,
        ?string $audioStoragePath = null,
        ?string $fileName = null,
    ): string {
        if ($audioStoragePath !== null) {
            $audioUrl = Storage::disk('r2')->temporaryUrl($audioStoragePath, now()->addHours(6));
        }
        try {
            $request_id = $this->deepGramClient->transcribe($audioUrl, $languageCode, $transcriptionId);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($e->getMessage());
        }

        return $request_id;
    }

    public function provider(): string
    {
        return 'deepgram';
    }
}
