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

    public function edit(string $transcriptionId, int $userId, string $transcript): Transcription
    {
        $transcription = $this->transcriptions->updateTranscriptForUser(
            $transcriptionId,
            $userId,
            $transcript,
        );

        $this->exports->dispatch($transcriptionId);

        return $transcription;
    }
}
