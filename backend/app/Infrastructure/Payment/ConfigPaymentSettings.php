<?php

namespace App\Infrastructure\Payment;

use App\Domain\Payment\Contracts\PaymentSettingsInterface;

final class ConfigPaymentSettings implements PaymentSettingsInterface
{
    public function supports(string $provider, string $currency): bool
    {
        return in_array($provider, ['paystack', 'flutterwave'], true) && (bool) config('payment.'.$provider.'.enabled') && filled(config('payment.'.$provider.'.secret')) && ($currency === 'NGN' || ($currency === 'USD' && (bool) config('payment.'.$provider.'.usd_enabled')));
    }
}
