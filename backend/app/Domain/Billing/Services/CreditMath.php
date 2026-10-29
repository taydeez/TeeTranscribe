<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Exceptions\BillingException;

final class CreditMath
{
    public static function decimal(string|int|null $value, int $precision = 2): int
    {
        $text = (string) $value;
        if (! preg_match('/\A(\d{1,9})(?:\.(\d{1,'.$precision.'}))?\z/', $text, $matches)) {
            throw new BillingException('A billing rate is not configured correctly.', 503);
        }

        return (int) $matches[1] * (10 ** $precision)
            + (int) str_pad($matches[2] ?? '', $precision, '0');
    }

    public static function prorate(int $rate, int $quantity, int $unitLength): int
    {
        if ($rate < 0 || $quantity < 0 || $quantity > 1_000_000_000 || $unitLength < 1 || $rate > 1_000_000_000) {
            throw new BillingException('The billing quantity or rate is invalid.', 422);
        }

        return intdiv($rate * $quantity + $unitLength - 1, $unitLength);
    }

    public static function usdCents(int $creditUnits, int $ngnPerUsdMicros): int
    {
        if ($creditUnits < 1 || $creditUnits > 100_000_000 || $ngnPerUsdMicros < 1) {
            throw new BillingException('The currency quote is unavailable.', 503);
        }

        return intdiv($creditUnits * 1_000_000 + $ngnPerUsdMicros - 1, $ngnPerUsdMicros);
    }
}
