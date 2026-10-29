<?php

namespace App\Domain\Admin\Billing\Contracts;

interface BillingConfigurationRepositoryInterface
{
    public function find(): ?array;

    public function save(array $configuration, array $before, int $version, int $actorId, string $reason): void;

    public function history(): array;

    public function report(string $from, string $until): array;
}
