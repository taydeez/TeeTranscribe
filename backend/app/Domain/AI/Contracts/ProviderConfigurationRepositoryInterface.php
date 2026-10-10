<?php

namespace App\Domain\AI\Contracts;

interface ProviderConfigurationRepositoryInterface
{
    public function find(string $activity): ?array;

    public function save(string $activity, array $configuration, int $version, int $actorId, string $reason): array;

    public function history(string $activity): array;
}
