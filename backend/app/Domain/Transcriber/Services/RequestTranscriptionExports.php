<?php

namespace App\Domain\Transcriber\Services;

use App\Domain\Transcriber\Contracts\TranscriptionExportDispatcherInterface;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Entities\Transcription;

final readonly class RequestTranscriptionExports
{
    public function __construct(private TranscriptionRepositoryInterface $repository, private TranscriptionExportDispatcherInterface $dispatcher) {}

    public function handle(string $id, int $userId): Transcription
    {
        $result = $this->repository->prepareExportsForUser($id, $userId);
        if ($result->changed) {
            $this->dispatcher->dispatch($id);
        }

        return $result->transcription;
    }
}
