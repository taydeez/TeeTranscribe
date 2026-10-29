<?php

namespace App\Domain\Translation\Services;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Translation\Contracts\TranslationGatewayResolverInterface;

final readonly class TranslationLanguages
{
    public function __construct(private TranslationGatewayResolverInterface $gateways) {}

    public function all(?string $provider = null): array
    {
        $languages = array_map(fn ($item) => $item + ['nigerian' => in_array($item['code'], ['yo', 'ig', 'ha'], true)], $this->gateways->languages($provider));
        $priority = ['yo' => 0, 'ig' => 1, 'ha' => 2, 'en' => 3];
        usort($languages, fn ($a, $b) => (($priority[$a['code']] ?? 4) <=> ($priority[$b['code']] ?? 4)) ?: strcasecmp($a['name'], $b['name']));

        return $languages;
    }

    public function validate(?string $source, string $target, ?string $provider = null): void
    {
        $codes = array_column($this->all($provider), 'code');
        if (! in_array($target, $codes, true) || ($source !== null && ! in_array($source, $codes, true))) {
            throw new BillingException('Select a supported source and target language.', 422);
        }
        if ($source === $target) {
            throw new BillingException('Choose a different output language.', 422);
        }
    }
}
