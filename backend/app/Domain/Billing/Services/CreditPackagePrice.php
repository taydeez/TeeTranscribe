<?php

namespace App\Domain\Billing\Services;

final class CreditPackagePrice
{
    public static function defaultUsdPrice(string|int $credits): string
    {
        $cents = intdiv(CreditMath::decimal($credits) + 99, 100);

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function calculate(array $package, string $currency): array
    {
        $units = CreditMath::decimal($package['credits']);
        $price = CreditMath::decimal($currency === 'USD' ? $package['price_usd'] : ($package['price_ngn'] ?? $package['credits']));

        return ['name' => $package['name'], 'credit_units' => $units,
            'amount_minor' => $price,
            'currency' => $currency, 'fx_ngn_per_usd_micros' => null];
    }
}
