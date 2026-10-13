<?php

namespace App\Infrastructure\Billing;

use App\Domain\AI\Services\ProviderRouting;
use App\Domain\Billing\Contracts\BillingConfigurationInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditMath;
use Carbon\CarbonImmutable;

final class ConfigBillingSettings implements BillingSettingsInterface
{
    public function __construct(private readonly ProviderRouting $routing, private readonly BillingConfigurationInterface $configuration) {}

    public function rate(string $activity, string $provider, string $model): array
    {
        $entry = ProviderRouting::findModel($this->routing->models($activity === 'dubbing' ? 'video_dubbing' : $activity, $provider), $model);
        $billingActivity = str_ends_with($activity, '_dubbing') ? 'dubbing' : $activity;
        $config = $entry['pricing'] ?? config('billing.rates.'.$billingActivity.'.'.$provider, [])[$model] ?? null;
        $policy = $this->configuration->current()['activities'][$activity === 'dubbing' ? 'video_dubbing' : $activity] ?? ['mode' => 'model'];
        if ($policy['mode'] === 'activity') {
            $unit = in_array($activity, ['translation', 'cleanup', 'summary'], true) ? '1000_characters' : 'minute';
            $cost = $config['provider_cost'] ?? null;
            $costMicros = filled($cost) ? CreditMath::decimal($cost, 6) : null;
            $providerUnitLength = match ($config['unit'] ?? $unit) {
                'character' => 1, '1000_characters' => 1000, default => 60000,
            };
            $config = ['unit' => $unit, 'credits' => $policy['credits'], 'provider_currency' => $config['provider_currency'] ?? null];
        }
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
            'provider_unit_length' => $providerUnitLength ?? $unitLength,
            'provider_cost_micros' => $costMicros ?? (filled($config['provider_cost'] ?? null) ? CreditMath::decimal($config['provider_cost'], 6) : null),
            'provider_currency' => $config['provider_currency'] ?? null,
        ];
    }

    public function model(string $provider): string
    {
        return (string) ($this->routing->configuration('transcription')['configuration']['providers'][$provider]['model'] ?? config('transcriber.'.$provider.'.model', 'default'));
    }

    public function packages(): array
    {
        $packages = [];
        foreach ($this->configuration->current()['packages'] as $package) {
            if ($package['enabled']) {
                $packages[$package['code']] = $package;
            }
        }

        return $packages;
    }

    public function fx(): array
    {
        $fx = $this->configuration->current()['fx'];
        $rate = CreditMath::decimal($fx['ngn_per_usd'], 6);
        $updated = $fx['updated_at'];
        if ($rate < 1 || ! is_string($updated) || $updated === '') {
            throw new BillingException('USD pricing is temporarily unavailable.', 503);
        }
        try {
            $time = CarbonImmutable::parse($updated);
        } catch (\Throwable $exception) {
            throw new BillingException('USD pricing is temporarily unavailable.', 503);
        }
        if ($time->isFuture() || $time->lt(now()->subHours($fx['max_age_hours']))) {
            throw new BillingException('The currency quote needs to be refreshed.', 503);
        }

        return ['ngn_per_usd_micros' => $rate, 'updated_at' => $time->toIso8601String()];
    }

    public function freeCreditUnits(): int
    {
        return CreditMath::decimal($this->configuration->current()['free_credits']);
    }

    public function quoteExpiresAt(): string
    {
        return now()->addMinutes($this->configuration->current()['quote_minutes'])->toIso8601String();
    }

    public function checkoutExpiresAt(): string
    {
        return now()->addMinutes($this->configuration->current()['checkout_minutes'])->toIso8601String();
    }
}
