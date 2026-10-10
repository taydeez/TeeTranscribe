<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Infrastructure\AI;

use App\Domain\AI\Services\ProviderRouting;
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
        private readonly ProviderRouting $routing,
    ) {}

    public function resolve(?string $languageCode = null, ?string $provider = null): TranscriberGatewayInterface
    {
        $provider ??= $this->routing->select('transcription', $languageCode)['provider'];
        if ($provider === 'google' && $languageCode !== null) {
            GoogleTranscriptionCapabilities::locale($languageCode);
        }

        return $this->gateway($provider);
    }

    public function definition(?string $languageCode = null): array
    {
        $selection = $this->routing->select('transcription', $languageCode);
        $this->resolve($languageCode, $selection['provider']);

        return $selection;
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
}
