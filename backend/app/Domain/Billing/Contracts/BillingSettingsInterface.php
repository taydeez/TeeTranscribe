<?php

namespace App\Domain\Billing\Contracts;

interface BillingSettingsInterface
{
    public function rate(string $activity, string $provider, string $model): array;

    public function model(string $provider): string;

    public function packages(): array;

    public function fx(): array;

    public function freeCreditUnits(): int;

    public function quoteExpiresAt(): string;

    public function checkoutExpiresAt(): string;
}
