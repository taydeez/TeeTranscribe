<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Domain\Transcriber\Services;

use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Contracts\TranscriptionSubmissionDispatcherInterface;
use App\Domain\Transcriber\Entities\Transcription;
use InvalidArgumentException;
use Throwable;

class TranscribeService
{
    public function __construct(
        private readonly TranscriberGatewayResolverInterface $transcriberGatewayResolver,
        private readonly TranscriptionRepositoryInterface $transcriptionRepository,
        private readonly TranscriptionSubmissionDispatcherInterface $submissionDispatcher,
    ) {}

    /** @param array<string, mixed> $transcriptionData */
    public function startNewTranscription(array $transcriptionData): Transcription
    {
        $audioPath = $transcriptionData['audio_path'] ?? $transcriptionData['audio_url'] ?? null;

        if (! is_string($audioPath) || $audioPath === '') {
            throw new InvalidArgumentException('An audio path or URL is required.');
        }

        $language = (string) ($transcriptionData['language_code'] ?? 'en');

        $urlFileName = rawurldecode(basename(parse_url($audioPath, PHP_URL_PATH) ?: $audioPath));
        $derivedName = pathinfo($urlFileName, PATHINFO_FILENAME);
        $derivedName = $derivedName !== '' ? $derivedName : 'transcription';
        $fileName = $transcriptionData['file_name'] ?? $derivedName;
        $name = $transcriptionData['name'] ?? pathinfo($fileName, PATHINFO_FILENAME);

        $gateway = $this->transcriberGatewayResolver->resolve($language);
        unset($transcriptionData['audio_url'], $transcriptionData['language_code']);

        $newTranscription = $this->transcriptionRepository->create([
            ...$transcriptionData,
            'audio_path' => $audioPath,
            'file_name' => $fileName,
            'name' => $name,
            'provider' => $gateway->provider(),
        ]);

        try {
            $this->submissionDispatcher->dispatch($newTranscription, $language);
        } catch (Throwable $exception) {
            $this->transcriptionRepository->update($newTranscription->id, ['status' => 'failed']);

            throw $exception;
        }

        return $newTranscription;
    }
}
