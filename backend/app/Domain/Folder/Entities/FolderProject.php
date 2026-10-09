<?php

namespace App\Domain\Folder\Entities;

final readonly class FolderProject
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public string $status,
        public ?string $sourceLanguage,
        public string $targetLanguage,
        public ?string $createdAt,
        public ?string $mediaType = null,
    ) {}
}
