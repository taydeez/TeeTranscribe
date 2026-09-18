<?php
/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Infrastructure\AI;

use App\Infrastructure\AI\Transcriber\DeepGram\DeepGramClient;
use App\Infrastructure\AI\Transcriber\Gateways\DeepGramGateway;

class TranscriberGatewayResolver {

    public static function resolve(): DeepGramGateway {

        $gateway = config('ai.transcriber.gateway', 'deepgram');

        return match ($gateway) {
            'deepgram' => new DeepGramGateway(new DeepGramClient()),
            default => throw new \InvalidArgumentException("Unsupported transcriber gateway: $gateway"),
        };
    }

}
