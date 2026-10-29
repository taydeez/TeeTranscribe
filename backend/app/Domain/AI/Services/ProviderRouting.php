<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Contracts\ProviderCatalogInterface;
use App\Domain\AI\Contracts\ProviderConfigurationRepositoryInterface;
use App\Domain\Billing\Exceptions\BillingException;

final readonly class ProviderRouting
{
    public function __construct(private ProviderConfigurationRepositoryInterface $repository, private ProviderCatalogInterface $catalog) {}

    public function configuration(string $activity): array
    {
        $this->assertActivity($activity);

        return $this->repository->find($activity) ?? ['activity' => $activity, 'version' => 0, 'configuration' => $this->catalog->defaults($activity), 'updatedAt' => null];
    }

    public function select(string $activity, ?string $language = null): array
    {
        $configuration = $this->configuration($activity)['configuration'];
        $language = strtolower(trim((string) $language));
        $provider = self::providerFor($configuration, $language);
        $settings = $configuration['providers'][$provider] ?? null;
        $definition = $this->catalog->providers($activity)[$provider] ?? null;
        if ($settings === null || $definition === null || ! $settings['enabled'] || ! $definition['configured']) {
            throw new BillingException('This activity is temporarily unavailable for the selected language.', 503);
        }
        $allowed = $settings['languages'];
        if ($language !== '' && $allowed !== [] && ! self::supports($allowed, $language)) {
            throw new BillingException('The selected language is not available for this activity.', 422);
        }
        if ($language !== '' && $definition['languages'] !== [] && ! self::supports($definition['languages'], $language)) {
            throw new BillingException('The selected language is not supported for this activity.', 422);
        }

        $model = self::modelFor($configuration, $language);
        $models = $configuration['models'][$provider] ?? $definition['model_catalog'] ?? null;
        if ($models !== null) {
            $entry = self::findModel($models, $model);
            if ($entry === null || ! $entry['enabled']) {
                throw new BillingException('The selected model is temporarily unavailable.', 503);
            }
            if ($language !== '' && $entry['languages'] !== [] && ! self::supports($entry['languages'], $language)) {
                throw new BillingException('The selected model does not support this language.', 422);
            }
        }

        return ['provider' => $provider, 'model' => $model];
    }

    public static function providerFor(array $configuration, ?string $language): string
    {
        $language = strtolower(trim((string) $language));

        $rule = self::ruleFor($configuration, $language);

        return is_array($rule) ? $rule['provider'] : ($rule ?? $configuration['default_provider']);
    }

    public static function modelFor(array $configuration, ?string $language): string
    {
        $rule = self::ruleFor($configuration, $language);

        return is_array($rule) ? ($rule['model'] ?? $configuration['providers'][$rule['provider']]['model'])
            : $configuration['providers'][self::providerFor($configuration, $language)]['model'];
    }

    private static function ruleFor(array $configuration, ?string $language): string|array|null
    {
        $language = strtolower(trim((string) $language));

        return $configuration['language_rules'][$language] ?? $configuration['language_rules'][explode('-', $language)[0]] ?? null;
    }

    public static function findModel(array $models, string $id): ?array
    {
        foreach ($models as $model) {
            if ($model['id'] === $id) {
                return $model;
            }
        }

        return null;
    }

    public static function usesProvider(array $configuration, string $provider): bool
    {
        if ($configuration['default_provider'] === $provider) {
            return true;
        }
        foreach ($configuration['language_rules'] as $rule) {
            if ((is_array($rule) ? $rule['provider'] : $rule) === $provider) {
                return true;
            }
        }

        return false;
    }

    public static function modelSupports(array $configuration, string $language, array $models): bool
    {
        $entry = self::findModel($models, self::modelFor($configuration, $language));

        return $entry !== null && $entry['enabled'] && ($entry['languages'] === [] || self::supports($entry['languages'], strtolower($language)));
    }

    public function models(string $activity, string $provider): array
    {
        return $this->configuration($activity)['configuration']['models'][$provider]
            ?? $this->catalog->providers($activity)[$provider]['model_catalog'] ?? [];
    }

    public function available(string $activity): array
    {
        $configuration = $this->configuration($activity)['configuration'];
        $items = [];
        foreach ($this->catalog->providers($activity) as $provider => $definition) {
            $settings = $configuration['providers'][$provider] ?? null;
            if ($settings !== null && $settings['enabled'] && $definition['configured']) {
                $items[$provider] = $settings;
            }
        }

        return $items;
    }

    public static function supports(array $languages, string $language): bool
    {
        foreach ($languages as $supported) {
            $supported = strtolower($supported);
            if ($language === $supported || (! str_contains($supported, '-') && explode('-', $language)[0] === $supported)) {
                return true;
            }
        }

        return false;
    }

    public function assertActivity(string $activity): void
    {
        if (! array_key_exists($activity, $this->catalog->activities())) {
            throw new BillingException('AI activity not found.', 404);
        }
    }
}
