<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Infrastructure\AI;

use App\Domain\Transcriber\Contracts\TranscriberGatewayInterface;
use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;
use App\Infrastructure\AI\Transcriber\Gateways\DeepGramGateway;
use App\Infrastructure\AI\Transcriber\Gateways\IntronGateway;
use InvalidArgumentException;

class TranscriberGatewayResolver implements TranscriberGatewayResolverInterface
{
    public function __construct(
        private readonly DeepGramGateway $deepGramGateway,
        private readonly IntronGateway $intronGateway,
    ) {}

    public function resolve(?string $languageCode = null): TranscriberGatewayInterface
    {
        if ($this->supportsIntron($languageCode)) {
            return $this->intronGateway;
        }

        $fallback = config('transcriber.fallback', 'deepgram');

        return match ($fallback) {
            'deepgram' => $this->deepGramGateway,
            'intron' => $this->intronGateway,
            default => throw new InvalidArgumentException("Unsupported fallback transcriber gateway: {$fallback}"),
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
