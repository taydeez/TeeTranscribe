<?php

namespace App\Domain\Transcriber\Entities;

use DateTimeImmutable;

final readonly class TranscriptionExport
{
    public function __construct(
        public string $id,
        public string $transcriptionId,
        public string $format,
        public string $status = 'pending',
        public ?string $storagePath = null,
        public ?string $failureReason = null,
        public ?DateTimeImmutable $processingStartedAt = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public string $variant = 'plain',
    ) {}
}
