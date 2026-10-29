<?php

namespace App\Domain\Billing\Entities;

final readonly class ActivityTask
{
    public function __construct(
        public string $type,
        public string $id,
        public ?int $userId,
        public string $activity,
        public string $provider,
        public string $status,
        public float $durationSeconds,
        public \DateTimeImmutable $startedAt,
        public ?string $cancelledAt = null,
        public ?string $failureReason = null,
    ) {}

    public function active(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }
}
