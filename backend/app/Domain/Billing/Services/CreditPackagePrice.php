<?php

namespace App\Domain\Billing\Services;

final class CreditPackagePrice
{
    public static function calculate(array $package, string $currency, ?array $fx): array
    {
        $units = CreditMath::decimal($package['credits']);
        $price = CreditMath::decimal($package['price_ngn'] ?? $package['credits']);

        return ['name' => $package['name'], 'credit_units' => $units,
            'amount_minor' => $currency === 'NGN' ? $price : CreditMath::usdCents($price, $fx['ngn_per_usd_micros']),
            'currency' => $currency, 'fx_ngn_per_usd_micros' => $fx['ngn_per_usd_micros'] ?? null];
    }
}
