<?php

namespace App\Domain\Transcriber\Entities;

use DateTimeImmutable;

final readonly class Transcription
{
    public function __construct(
        public string $id,
        public ?int $userId,
        public string $audioPath,
        public string $fileName,
        public string $name,
        public string $status = 'pending',
        public ?string $providerRequestId = null,
        public ?string $transcript = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?string $guestSessionId = null,
        public ?float $duration = null,
        public ?string $folderName = null,
        public string $provider = 'deepgram',
        public ?string $audioStoragePath = null,
        public array $segments = [],
    ) {}
}
