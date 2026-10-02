<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Infrastructure\AI;

use App\Domain\Transcriber\Contracts\TranscriberGatewayInterface;
use App\Infrastructure\AI\Transcriber\DeepGram\DeepGramClient;
use App\Infrastructure\AI\Transcriber\Gateways\DeepGramGateway;
use App\Infrastructure\AI\Transcriber\Gateways\IntronGateway;
use App\Infrastructure\AI\Transcriber\Intron\IntronClient;

class TranscriberGatewayResolver
{
    public static function resolve(?string $languageCode = null): TranscriberGatewayInterface
    {

        $gateway = config('transcriber.default', 'deepgram');

        return match ($gateway) {
            'deepgram' => new DeepGramGateway(new DeepGramClient),
            'intron' => new IntronGateway(new IntronClient),
            default => throw new \InvalidArgumentException("Unsupported transcriber gateway: $gateway"),
        };
    }
}
