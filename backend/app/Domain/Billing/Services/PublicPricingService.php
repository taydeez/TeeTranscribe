<?php

namespace App\Domain\Billing\Services;

use App\Domain\AI\Services\FeatureAvailability;
use App\Domain\AI\Services\ProviderRouting;
use App\Domain\Billing\Contracts\BillingConfigurationInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;

final readonly class PublicPricingService
{
    public function __construct(private BillingConfigurationInterface $configuration, private BillingSettingsInterface $settings,
        private FeatureAvailability $features, private ProviderRouting $routing) {}

    public function catalog(): array
    {
        $configuration = $this->configuration->current();
        $packages = [];
        if (in_array('USD', $configuration['enabled_currencies'], true)) {
            foreach ($configuration['packages'] as $package) {
                if ($package['enabled']) {
                    $price = CreditPackagePrice::calculate($package, 'USD');
                    $packages[] = ['id' => $package['code'], 'name' => $price['name'], 'credit_units' => $price['credit_units'],
                        'amount_minor' => $price['amount_minor'], 'currency' => 'USD'];
                }
            }
        }
        $rates = [];
        foreach ($this->features->all() as $activity => $enabled) {
            if (! $enabled) {
                continue;
            }
            try {
                $selection = $this->routing->select($activity);
                $rate = $this->settings->rate($activity, $selection['provider'], $selection['model']);
                $rates[] = ['activity' => $activity, 'unit' => $activity === 'translation' ? '1000_characters' : 'minute',
                    'credit_units' => CreditMath::prorate($rate['credit_units'], $activity === 'translation' ? 1000 : 60000, $rate['unit_length'])];
            } catch (BillingException) {
                $rates[] = ['activity' => $activity, 'unit' => $activity === 'translation' ? '1000_characters' : 'minute', 'credit_units' => null];
            }
        }

        return ['packages' => $packages, 'rates' => $rates, 'free_credit_units' => $this->settings->freeCreditUnits()];
    }
}
