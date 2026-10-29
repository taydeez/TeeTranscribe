<?php

namespace App\Domain\Folder\Entities;

use DateTimeImmutable;

final readonly class Folder
{
    /** @param list<string> $transcriptionIds */
    public function __construct(
        public string $id,
        public int $userId,
        public string $name,
        public array $transcriptionIds = [],
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public int $translationCount = 0,
        public int $dubbingCount = 0,
    ) {}
}
