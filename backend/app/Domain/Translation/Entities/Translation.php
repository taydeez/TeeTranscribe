<?php

namespace App\Domain\Translation\Entities;

final readonly class Translation
{
    public function __construct(
        public string $id,
        public int $userId,
        public string $name,
        public string $sourceText,
        public string $targetLanguage,
        public string $status = 'pending',
        public ?string $sourceLanguage = null,
        public ?string $detectedLanguage = null,
        public ?string $transcriptionId = null,
        public ?string $translatedText = null,
        public array $sourceSegments = [],
        public array $segments = [],
        public array $exports = [],
        public int $exportRevision = 0,
        public ?string $failureReason = null,
        public ?string $createdAt = null,
        public ?string $folderId = null,
        public string $provider = 'google',
        public string $model = 'nmt',
    ) {}
}
