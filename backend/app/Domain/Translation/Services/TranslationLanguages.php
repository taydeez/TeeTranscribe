<?php

namespace App\Domain\Translation\Services;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Translation\Contracts\TranslationGatewayInterface;

final readonly class TranslationLanguages
{
    public function __construct(private TranslationGatewayInterface $gateway) {}

    public function all(): array
    {
        $languages = array_map(fn ($item) => $item + ['nigerian' => in_array($item['code'], ['yo', 'ig', 'ha'], true)], $this->gateway->languages());
        $priority = ['yo' => 0, 'ig' => 1, 'ha' => 2, 'en' => 3];
        usort($languages, fn ($a, $b) => (($priority[$a['code']] ?? 4) <=> ($priority[$b['code']] ?? 4)) ?: strcasecmp($a['name'], $b['name']));

        return $languages;
    }

    public function validate(?string $source, string $target): void
    {
        $codes = array_column($this->all(), 'code');
        if (! in_array($target, $codes, true) || ($source !== null && ! in_array($source, $codes, true))) {
            throw new BillingException('Select a supported source and target language.', 422);
        }
        if ($source === $target) {
            throw new BillingException('Choose a different output language.', 422);
        }
    }
}
