<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Infrastructure\AI;

use App\Domain\Transcriber\Contracts\TranscriberGatewayInterface;
use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;
use App\Domain\Transcriber\Services\GoogleTranscriptionCapabilities;
use App\Infrastructure\AI\Transcriber\Gateways\DeepGramGateway;
use App\Infrastructure\AI\Transcriber\Gateways\ElevenLabsGateway;
use App\Infrastructure\AI\Transcriber\Gateways\GoogleGateway;
use App\Infrastructure\AI\Transcriber\Gateways\IntronGateway;
use App\Infrastructure\AI\Transcriber\Gateways\OpenAIGateway;
use InvalidArgumentException;

class TranscriberGatewayResolver implements TranscriberGatewayResolverInterface
{
    public function __construct(
        private readonly DeepGramGateway $deepGramGateway,
        private readonly IntronGateway $intronGateway,
        private readonly GoogleGateway $googleGateway,
        private readonly ElevenLabsGateway $elevenLabsGateway,
        private readonly OpenAIGateway $openAIGateway,
    ) {}

    public function resolve(?string $languageCode = null, ?string $provider = null): TranscriberGatewayInterface
    {
        $language = strtolower(trim((string) $languageCode));
        $overrides = config('transcriber.language_providers', []);
        $provider ??= $overrides[$language] ?? $overrides[explode('-', $language)[0]] ?? null;
        if ($provider !== null) {
            if ($provider === 'google' && $languageCode !== null) {
                GoogleTranscriptionCapabilities::locale($languageCode);
            }

            return $this->gateway($provider);
        }
        if ($this->supportsIntron($languageCode)) {
            return $this->intronGateway;
        }

        $fallback = config('transcriber.fallback', 'deepgram');
        if ($fallback === 'google' && $languageCode !== null) {
            GoogleTranscriptionCapabilities::locale($languageCode);
        }

        return $this->gateway($fallback);
    }

    private function gateway(string $provider): TranscriberGatewayInterface
    {
        return match ($provider) {
            'deepgram' => $this->deepGramGateway,
            'intron' => $this->intronGateway,
            'google' => $this->googleGateway,
            'elevenlabs' => $this->elevenLabsGateway,
            'openai' => $this->openAIGateway,
            default => throw new InvalidArgumentException("Unsupported transcriber gateway: {$provider}"),
        };
    }

    private function supportsIntron(?string $languageCode): bool
    {
        $language = strtolower(trim((string) $languageCode));
        if ($language === '') {
            return false;
        }

        $baseLanguage = explode('-', $language, 2)[0];

        return collect(config('transcriber.intron.languages', []))
            ->contains(function (mixed $supported) use ($language, $baseLanguage): bool {
                $supportedLanguage = strtolower(trim((string) $supported));

                return str_contains($supportedLanguage, '-')
                    ? $language === $supportedLanguage
                    : $baseLanguage === $supportedLanguage;
            });
    }
}
