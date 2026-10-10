<?php

namespace App\Domain\Dubbing\Services;

use App\Domain\AI\Services\ProviderRouting;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Contracts\VideoSubtitleTranscriberInterface;
use App\Domain\Translation\Services\TranslationLanguages;

final readonly class VideoSubtitleLanguages
{
    public function __construct(private VideoSubtitleTranscriberInterface $transcriber, private TranslationLanguages $translation,
        private DubbingLanguages $dubbing, private BillingSettingsInterface $settings, private ProviderRouting $routing) {}

    public function definition(): array
    {
        $definition = $this->transcriber->definition();
        if (! $definition['configured']) {
            throw new BillingException('Subtitles are not configured yet.', 503);
        }
        $this->settings->rate('subtitles', $definition['provider'], $definition['model']);

        return $definition;
    }

    public function catalog(): array
    {
        try {
            $definition = $this->definition();

            return ['configured' => true, 'data' => $this->translation->all('google'), 'sourceLanguages' => array_values(array_filter(
                $this->dubbing->sourceLanguages(), fn ($language) => in_array($language['code'], $definition['source_codes'], true)))];
        } catch (BillingException) {
            return ['configured' => false, 'data' => [], 'sourceLanguages' => []];
        }
    }

    public function validate(?string $source, string $target): array
    {
        $selection = $this->routing->select('subtitles', $source);
        $definition = $this->definition();
        if (($source !== null && ! in_array($source, $definition['source_codes'], true))
            || ! in_array($target, array_column($this->translation->all('google'), 'code'), true)) {
            throw new BillingException('Select supported spoken and subtitle languages.', 422);
        }

        return array_replace($definition, $selection);
    }
}
