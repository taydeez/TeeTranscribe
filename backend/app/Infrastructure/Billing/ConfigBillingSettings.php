<?php

namespace App\Infrastructure\Billing;

use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditMath;
use Carbon\CarbonImmutable;

final class ConfigBillingSettings implements BillingSettingsInterface
{
    public function rate(string $activity, string $provider, string $model): array
    {
        $config = config('billing.rates.'.$activity.'.'.$provider, [])[$model] ?? null;
        if (! is_array($config) || ! isset($config['credits'])) {
            throw new BillingException('Pricing is not available for this language yet.', 503);
        }
        $unitLength = match ($config['unit'] ?? '') {
            'minute' => 60000, 'character' => 1, '1000_characters' => 1000,
            default => throw new BillingException('This activity has no supported billing unit.', 503),
        };
        if ($activity === 'transcription' && ($config['unit'] ?? '') !== 'minute') {
            throw new BillingException('Transcription pricing must use minutes.', 503);
        }
        $units = CreditMath::decimal($config['credits']);
        if ($units < 1) {
            throw new BillingException('A positive activity price must be configured.', 503);
        }

        return [
            'unit' => $config['unit'], 'unit_length' => $unitLength, 'credit_units' => $units,
            'provider_cost_micros' => filled($config['provider_cost'] ?? null) ? CreditMath::decimal($config['provider_cost'], 6) : null,
            'provider_currency' => $config['provider_currency'] ?? null,
        ];
    }

    public function model(string $provider): string
    {
        return (string) config('transcriber.'.$provider.'.model', 'default');
    }

    public function packages(): array
    {
        return config('billing.packages', []);
    }

    public function fx(): array
    {
        $rate = CreditMath::decimal(config('billing.fx.ngn_per_usd'), 6);
        $updated = config('billing.fx.updated_at');
        if ($rate < 1 || ! is_string($updated) || $updated === '') {
            throw new BillingException('USD pricing is temporarily unavailable.', 503);
        }
        try {
            $time = CarbonImmutable::parse($updated);
        } catch (\Throwable $exception) {
            throw new BillingException('USD pricing is temporarily unavailable.', 503);
        }
        if ($time->isFuture() || $time->lt(now()->subHours(config('billing.fx.max_age_hours', 24)))) {
            throw new BillingException('The currency quote needs to be refreshed.', 503);
        }

        return ['ngn_per_usd_micros' => $rate, 'updated_at' => $time->toIso8601String()];
    }

    public function freeCreditUnits(): int
    {
        return CreditMath::decimal(config('billing.free_credits', '0'));
    }

    public function quoteExpiresAt(): string
    {
        return now()->addMinutes(config('billing.quote_minutes', 45))->toIso8601String();
    }

    public function checkoutExpiresAt(): string
    {
        return now()->addMinutes(config('billing.checkout_minutes', 30))->toIso8601String();
    }
}
