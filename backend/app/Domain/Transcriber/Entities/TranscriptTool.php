<?php

namespace App\Domain\Transcriber\Entities;

final readonly class TranscriptTool
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public string $id, public int $userId, public string $transcriptionId,
        public string $operation, public string $status, public string $sourceHash,
        public string $sourceText, public array $sourceSegments, public string $model,
        public ?array $result = null, public ?string $failureReason = null, public ?string $createdAt = null,
        public array $progress = [],
    ) {}
}
