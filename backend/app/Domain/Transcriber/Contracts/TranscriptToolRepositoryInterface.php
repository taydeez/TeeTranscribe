<?php

namespace App\Domain\Transcriber\Contracts;

use App\Domain\Transcriber\Entities\TranscriptTool;

interface TranscriptToolRepositoryInterface
{
    public function find(string $id, ?int $userId = null, bool $lock = false): ?TranscriptTool;

    /** @return list<TranscriptTool> */
    public function forTranscription(string $id, int $userId): array;

    public function reusable(string $id, int $userId, string $operation, string $sourceHash): ?TranscriptTool;

    public function create(array $data): TranscriptTool;

    public function update(string $id, array $data): TranscriptTool;
}
