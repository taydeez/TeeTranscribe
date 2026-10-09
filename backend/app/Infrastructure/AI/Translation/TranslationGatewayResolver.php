<?php

namespace App\Infrastructure\AI\Translation;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Translation\Contracts\TranslationGatewayInterface;
use App\Domain\Translation\Contracts\TranslationGatewayResolverInterface;
use App\Infrastructure\AI\OpenAI\OpenAIClient;
use App\Infrastructure\AI\Translation\Gateways\OpenAITranslationGateway;

final readonly class TranslationGatewayResolver implements TranslationGatewayResolverInterface
{
    public function __construct(private TranslationGatewayInterface $google, private OpenAIClient $openai) {}

    public function definition(): array
    {
        $provider = (string) config('translation.provider', 'google');
        $this->resolve($provider);
        $model = $provider === 'google' ? 'nmt' : (string) config('translation.openai.model', config('openai.text_model', 'gpt-4.1-mini'));

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
        return $this->resolve($provider ?? $this->definition()['provider'])->languages();
    }
}
