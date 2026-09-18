<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Domain\Transcriber\Services;

use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Entities\Transcription;
use App\Infrastructure\AI\TranscriberGatewayResolver;
use InvalidArgumentException;

class TranscribeService
{
    public function __construct(
        private readonly TranscriberGatewayResolver $transcriberGatewayResolver,
        private readonly TranscriptionRepositoryInterface $transcriptionRepository,
    ) {}


    /** @param array<string, mixed> $transcriptionData */
    public function startNewTranscription(array $transcriptionData): Transcription
    {
        $audioPath = $transcriptionData['audio_path'] ?? $transcriptionData['audio_url'] ?? null;

        if (!is_string($audioPath) || $audioPath === '') {
            throw new InvalidArgumentException('An audio path or URL is required.');
        }

        $language = $transcriptionData['language_code'] ?? null;

        $fileName = $transcriptionData['file_name']
            ?? rawurldecode(basename(parse_url($audioPath, PHP_URL_PATH) ?: $audioPath));
        $name = $transcriptionData['name'] ?? pathinfo($fileName, PATHINFO_FILENAME);

        unset($transcriptionData['audio_url'], $transcriptionData['language_code']);

        $newTranscription = $this->transcriptionRepository->create([
            ...$transcriptionData,
            'audio_path' => $audioPath,
            'file_name' => $fileName,
            'name' => $name,
        ]);

        $this->transcribe($audioPath, $language, $newTranscription->id);

        return $newTranscription;
    }



    private function transcribe(string $audioUrl, string $languageCode, string $transcriptionId): string
    {
        return $this->transcriberGatewayResolver->resolve()->transcribe($audioUrl, $languageCode, $transcriptionId);
    }



}
