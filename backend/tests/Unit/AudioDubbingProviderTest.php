<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Contracts\DubbingGatewayResolverInterface;
use App\Infrastructure\AI\Dubbing\ElevenLabs\ElevenLabsDubbingGateway;
use Tests\TestCase;

uses(TestCase::class);

test('audio provider selection stays separate from video provider selection', function () {
    config(['dubbing.provider' => 'heygen', 'dubbing.heygen.key' => 'video-key', 'dubbing.heygen.mode' => 'precision',
        'dubbing.audio_provider' => 'elevenlabs', 'dubbing.key' => 'audio-key']);
    $resolver = app(DubbingGatewayResolverInterface::class);
    expect($resolver->definition('audio'))->toMatchArray(['provider' => 'elevenlabs', 'model' => 'dubbing_v2', 'configured' => true]);
    expect($resolver->definition()['provider'])->toBe('heygen');
    expect($resolver->resolve('elevenlabs'))->toBeInstanceOf(ElevenLabsDubbingGateway::class);
});

test('an unsupported audio provider cannot fall through to the video dubbing provider', function () {
    config(['dubbing.audio_provider' => 'heygen']);
    expect(fn () => app(DubbingGatewayResolverInterface::class)->definition('audio'))->toThrow(BillingException::class);
});
