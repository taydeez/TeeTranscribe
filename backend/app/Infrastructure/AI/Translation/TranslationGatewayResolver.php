<?php

namespace App\Infrastructure\AI\Translation;

use App\Domain\AI\Services\ProviderRouting;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Translation\Contracts\TranslationGatewayInterface;
use App\Domain\Translation\Contracts\TranslationGatewayResolverInterface;
use App\Infrastructure\AI\OpenAI\OpenAIClient;
use App\Infrastructure\AI\Translation\Gateways\OpenAITranslationGateway;

final readonly class TranslationGatewayResolver implements TranslationGatewayResolverInterface
{
    public function __construct(private TranslationGatewayInterface $google, private OpenAIClient $openai, private ProviderRouting $routing) {}

    public function definition(?string $language = null): array
    {
        $selection = $this->routing->select('translation', $language);
        $provider = $selection['provider'];
        $this->resolve($provider);
        $model = $selection['model'];

        return ['provider' => $provider, 'model' => $model, 'max_segments' => $provider === 'openai' ? 4000 : 20000,
            'configured' => $model !== '' && filled(config($provider === 'google' ? 'translation.google.key' : 'openai.key'))];
    }

    public function resolve(string $provider, ?string $model = null): TranslationGatewayInterface
    {
        return match ($provider) {
            'google' => $this->google,
            'openai' => new OpenAITranslationGateway($this->openai, $model),
            default => throw new BillingException('Translation is not configured yet.', 503),
        };
    }

    public function languages(?string $provider = null): array
    {
        if ($provider !== null) {
            return $this->resolve($provider)->languages();
        }
        $configuration = $this->routing->configuration('translation')['configuration'];
        $items = [];
        foreach ($this->routing->available('translation') as $name => $settings) {
            if (! ProviderRouting::usesProvider($configuration, $name)) {
                continue;
            }
            $models = $this->routing->models('translation', $name);
            foreach ($this->resolve($name)->languages() as $language) {
                if (ProviderRouting::providerFor($configuration, $language['code']) === $name
                    && ProviderRouting::modelSupports($configuration, $language['code'], $models)
                    && ($settings['languages'] === [] || ProviderRouting::supports($settings['languages'], strtolower($language['code'])))) {
                    $items[$language['code']] = $language;
                }
            }
        }

        return array_values($items);
    }
}
