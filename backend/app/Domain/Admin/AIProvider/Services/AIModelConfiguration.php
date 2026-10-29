<?php

namespace App\Domain\Admin\AIProvider\Services;

use App\Domain\AI\Services\ProviderRouting;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditMath;

final class AIModelConfiguration
{
    public function check(string $activity, array $models, array $catalog): void
    {
        if (array_diff(array_keys($models), array_keys($catalog)) !== [] || array_diff(array_keys($catalog), array_keys($models)) !== []) {
            throw new BillingException('Provide a model catalog for each supported provider.', 422);
        }
        foreach ($models as $provider => $entries) {
            $ids = [];
            foreach ($entries as $entry) {
                $id = $entry['id'];
                if (in_array($id, $ids, true) || (! $catalog[$provider]['custom_models'] && ! in_array($id, $catalog[$provider]['allowed_model_ids'], true))) {
                    throw new BillingException('Model IDs must be unique and compatible with the provider integration.', 422);
                }
                $ids[] = $id;
                foreach ($entry['languages'] as $language) {
                    if ($catalog[$provider]['languages'] !== [] && ! ProviderRouting::supports($catalog[$provider]['languages'], strtolower($language))) {
                        throw new BillingException('A model language is unsupported by this provider.', 422);
                    }
                }
                $pricing = $entry['pricing'];
                $text = in_array($activity, ['translation', 'cleanup', 'summary'], true);
                if (! in_array($pricing['unit'], $text ? ['character', '1000_characters'] : ['minute'], true)) {
                    throw new BillingException('Choose a billing unit suitable for this activity.', 422);
                }
                foreach (['credits' => 2, 'provider_cost' => 6] as $field => $precision) {
                    if ($pricing[$field] === null) {
                        continue;
                    }
                    try {
                        $amount = CreditMath::decimal($pricing[$field], $precision);
                    } catch (BillingException) {
                        throw new BillingException('Enter valid model prices using at most two credit decimals or six provider-cost decimals.', 422);
                    }
                    if ($amount > 1_000_000_000 || ($field === 'credits' && $amount < 1)) {
                        throw new BillingException('Model prices are outside the supported billing range.', 422);
                    }
                }
                if ($activity === 'subtitles' && ! $entry['capabilities']['timestamps']) {
                    throw new BillingException('Subtitle models must provide timestamps.', 422);
                }
            }
        }
    }
}
