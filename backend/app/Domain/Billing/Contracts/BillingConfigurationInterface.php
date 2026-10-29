<?php

namespace App\Domain\Billing\Contracts;

interface BillingConfigurationInterface
{
    public function defaults(): array;

    public function current(): array;
}
