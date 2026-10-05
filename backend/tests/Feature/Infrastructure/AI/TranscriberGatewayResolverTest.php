<?php

use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;
use App\Infrastructure\AI\Transcriber\Gateways\DeepGramGateway;
use App\Infrastructure\AI\Transcriber\Gateways\IntronGateway;

test('it routes Nigerian languages to Intron', function (string $language): void {
    config()->set('transcriber.intron.languages', ['en-NG', 'pcm', 'yo', 'ig', 'ha']);

    $gateway = app(TranscriberGatewayResolverInterface::class)->resolve($language);

    expect($gateway)->toBeInstanceOf(IntronGateway::class);
})->with(['en-NG', 'pcm', 'pcm-NG', 'yo', 'ig-NG', 'ha']);

test('it routes other languages to the configured fallback provider', function (): void {
    config()->set('transcriber.intron.languages', ['en-NG', 'pcm', 'yo', 'ig', 'ha']);
    config()->set('transcriber.fallback', 'deepgram');

    expect(app(TranscriberGatewayResolverInterface::class)->resolve('fr-CA'))
        ->toBeInstanceOf(DeepGramGateway::class);
});
