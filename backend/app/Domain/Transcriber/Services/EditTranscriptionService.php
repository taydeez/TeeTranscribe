<?php

namespace App\Domain\Transcriber\Services;

use App\Domain\Transcriber\Contracts\TranscriptionExportDispatcherInterface;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Entities\Transcription;

final readonly class EditTranscriptionService
{
    public function __construct(
        private TranscriptionRepositoryInterface $transcriptions,
        private TranscriptionExportDispatcherInterface $exports,
    ) {}

    public function edit(string $transcriptionId, int $userId, string $transcript, ?array $segments = null): Transcription
    {
        $result = $segments === null ? $this->transcriptions->updateTranscriptForUser(
            $transcriptionId,
            $userId,
            $transcript,
        ) : $this->transcriptions->updateTranscriptForUser($transcriptionId, $userId, $transcript, $segments);

        if ($result->changed) {
            $this->exports->dispatch($transcriptionId);
        }

        return $result->transcription;
    }
}
