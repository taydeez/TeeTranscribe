<?php

namespace App\Infrastructure\Payment;

use App\Domain\Billing\Contracts\BillingConfigurationInterface;
use App\Domain\Payment\Contracts\PaymentSettingsInterface;

final class ConfigPaymentSettings implements PaymentSettingsInterface
{
    public function __construct(private readonly BillingConfigurationInterface $configuration) {}

    public function supports(string $provider, string $currency): bool
    {
        $settings = $this->configuration->current();
        $gateway = $settings['gateways'][$provider] ?? null;

        return $gateway !== null && $gateway['enabled'] && filled(config('payment.'.$provider.'.secret'))
            && in_array($currency, $settings['enabled_currencies'], true) && in_array($currency, $gateway['currencies'], true);
    }
}
