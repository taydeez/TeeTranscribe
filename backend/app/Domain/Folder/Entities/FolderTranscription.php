<?php

namespace App\Domain\Folder\Entities;

use App\Domain\Transcriber\Entities\TranscriptionExport;
use DateTimeImmutable;

final readonly class FolderTranscription
{
    /** @param list<TranscriptionExport> $exports */
    public function __construct(
        public string $id,
        public string $name,
        public string $fileName,
        public string $status,
        public ?string $transcript = null,
        public ?float $duration = null,
        public array $exports = [],
        public ?DateTimeImmutable $createdAt = null,
    ) {}
}
