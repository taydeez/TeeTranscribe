<?php

namespace App\Domain\Dubbing\Entities;

final readonly class Dubbing
{
    public function __construct(
        public string $id, public int $userId, public string $name,
        public string $sourceStoragePath, public ?string $sourceLanguage, public string $targetLanguage,
        public int $durationMs, public string $status,
        public ?string $projectId = null, public ?string $languageId = null,
        public ?string $submissionStartedAt = null, public ?string $providerCompletedAt = null,
        public ?string $audioStoragePath = null, public ?string $videoStoragePath = null,
        public ?string $failureReason = null, public ?string $createdAt = null,
        public string $provider = 'elevenlabs', public string $model = 'dubbing_v2', public array $providerOptions = [],
        public bool $subtitlesEnabled = false, public ?string $subtitleStyle = null, public ?string $subtitleStatus = null,
        public ?string $subtitleStoragePath = null, public ?string $captionedVideoStoragePath = null,
        public string $operation = 'dubbing', public ?array $sourceSubtitleSegments = null,
        public ?array $translatedSubtitleSegments = null, public ?string $detectedSourceLanguage = null,
        public string $mediaType = 'video', public ?string $audioPreviewStoragePath = null,
        public ?string $folderId = null,
    ) {}
}
