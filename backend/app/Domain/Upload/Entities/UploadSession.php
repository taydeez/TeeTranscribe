<?php

namespace App\Domain\Upload\Entities;

use App\Domain\Upload\Enums\UploadStatus;
use DateTimeImmutable;

final class UploadSession
{
    public function __construct(
        public string $id,
        public int $userId,
        public string $clientKey,
        public string $filename,
        public string $contentType,
        public int $size,
        public string $fingerprint,
        public string $storagePath,
        public int $partSize,
        public DateTimeImmutable $expiresAt,
        public ?string $providerUploadId = null,
        public UploadStatus $status = UploadStatus::Uploading,
    ) {}

    public function partCount(): int
    {
        return (int) ceil($this->size / $this->partSize);
    }

    public function partBytes(int $number): int
    {
        return min($this->partSize, $this->size - ($number - 1) * $this->partSize);
    }
}
