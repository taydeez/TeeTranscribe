<?php

namespace App\Domain\Admin\AIProvider\Services;

use App\Domain\AI\Contracts\ProviderCatalogInterface;
use App\Domain\AI\Contracts\ProviderConfigurationRepositoryInterface;
use App\Domain\AI\Services\ProviderRouting;
use App\Domain\Billing\Exceptions\BillingException;

final readonly class AIProviderConfigurationService
{
    public function __construct(private ProviderConfigurationRepositoryInterface $repository, private ProviderCatalogInterface $catalog, private ProviderRouting $routing, private AIModelConfiguration $models) {}

    public function all(): array
    {
        $items = [];
        foreach ($this->catalog->activities() as $activity => $name) {
            $items[] = $this->record($activity) + ['name' => $name];
        }

        return $items;
    }

    public function record(string $activity): array
    {
        $record = $this->routing->configuration($activity);
        $catalog = $this->catalog->providers($activity);
        foreach ($catalog as $provider => &$definition) {
            $entries = $record['configuration']['models'][$provider] ?? $definition['model_catalog'];
            $record['configuration']['models'][$provider] = $entries;
            $definition['model_catalog'] = $entries;
            $definition['models'] = array_column($entries, 'id');
        }
        unset($definition);

        return $record + ['catalog' => $catalog, 'history' => $this->repository->history($activity)];
    }

    public function update(string $activity, array $input, int $actorId): array
    {
        $this->routing->assertActivity($activity);
        $catalog = $this->catalog->providers($activity);
        $configuration = $input['configuration'];
        $configuration['models'] ??= $this->record($activity)['configuration']['models'];
        $this->models->check($activity, $configuration['models'], $catalog);
        if (in_array($activity, ['cleanup', 'summary'], true) && $configuration['language_rules'] !== []) {
            throw new BillingException('Transcript tools use an activity default because the transcript language is not stored.', 422);
        }
        $providers = $configuration['providers'];
        if (array_diff(array_keys($catalog), array_keys($providers)) !== [] || array_diff(array_keys($providers), array_keys($catalog)) !== []
            || ! isset($providers[$configuration['default_provider']])) {
            throw new BillingException('Choose providers supported by this activity.', 422);
        }
        foreach ($providers as $provider => $settings) {
            if (ProviderRouting::findModel($configuration['models'][$provider], $settings['model']) === null) {
                throw new BillingException('Choose a model from this provider’s catalog.', 422);
            }
            foreach ($settings['languages'] as $language) {
                if ($catalog[$provider]['languages'] !== [] && ! ProviderRouting::supports($catalog[$provider]['languages'], strtolower($language))) {
                    throw new BillingException('A language restriction is unsupported by this provider.', 422);
                }
            }
        }
        foreach ($configuration['language_rules'] as $language => $rule) {
            $provider = is_array($rule) ? ($rule['provider'] ?? '') : $rule;
            if (! preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $language) || ! isset($providers[$provider]) || ! $providers[$provider]['enabled']) {
                throw new BillingException('Language rules must reference an enabled provider and a language code.', 422);
            }
            $allowed = $providers[$provider]['languages'];
            $model = is_array($rule) ? ($rule['model'] ?? '') : $providers[$provider]['model'];
            $entry = ProviderRouting::findModel($configuration['models'][$provider], $model);
            if ($entry === null || ! $entry['enabled'] || ($entry['languages'] !== [] && ! ProviderRouting::supports($entry['languages'], $language))) {
                throw new BillingException('A language rule must select an enabled model supporting that language.', 422);
            }
            if (($allowed !== [] && ! ProviderRouting::supports($allowed, $language))
                || ($catalog[$provider]['languages'] !== [] && ! ProviderRouting::supports($catalog[$provider]['languages'], $language))) {
                throw new BillingException('A language rule conflicts with the provider’s supported languages.', 422);
            }
        }
        $this->repository->save($activity, $configuration, $input['version'], $actorId, trim($input['reason']));

        return $this->record($activity);
    }
}
