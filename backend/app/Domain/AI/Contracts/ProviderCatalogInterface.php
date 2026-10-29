<?php

namespace App\Domain\AI\Contracts;

interface ProviderCatalogInterface
{
    public function activities(): array;

    public function providers(string $activity): array;

    public function defaults(string $activity): array;
}
