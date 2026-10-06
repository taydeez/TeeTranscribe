<?php

namespace App\Domain\Payment\Contracts;

interface PaymentSettingsInterface
{
    public function supports(string $provider, string $currency): bool;
}
