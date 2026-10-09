<?php

namespace App\Infrastructure\AI\Dubbing;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Contracts\DubbingGatewayInterface;
use App\Domain\Dubbing\Contracts\DubbingGatewayResolverInterface;
use App\Domain\Dubbing\Services\DubbingLanguages;
use App\Infrastructure\AI\Dubbing\ElevenLabs\ElevenLabsDubbingGateway;
use App\Infrastructure\AI\Dubbing\HeyGen\HeyGenDubbingGateway;

final readonly class DubbingGatewayResolver implements DubbingGatewayResolverInterface
{
    public function __construct(private ElevenLabsDubbingGateway $elevenlabs, private HeyGenDubbingGateway $heygen) {}

    public function definition(string $mediaType = 'video'): array
    {
        $provider = (string) config($mediaType === 'audio' ? 'dubbing.audio_provider' : 'dubbing.provider', 'elevenlabs');
        if ($mediaType === 'audio' && $provider !== 'elevenlabs') {
            throw new BillingException('Audio dubbing is not configured yet.', 503);
        }
        $this->resolve($provider);
        $model = $provider === 'heygen' ? (string) config('dubbing.heygen.mode', 'precision') : 'dubbing_v2';
        if ($provider === 'heygen' && ! in_array($model, ['speed', 'precision'], true)) {
            throw new BillingException('Dubbing is not configured yet.', 503);
        }
        if ($provider === 'heygen' && config('dubbing.heygen.speaker_num') !== null && config('dubbing.heygen.speaker_num') < 1) {
            throw new BillingException('Dubbing is not configured yet.', 503);
        }

        return ['provider' => $provider, 'model' => $model,
            'options' => $provider === 'heygen' ? array_filter(['speaker_num' => config('dubbing.heygen.speaker_num'),
                'disable_music_track' => (bool) config('dubbing.heygen.disable_music_track'),
                'enable_speech_enhancement' => (bool) config('dubbing.heygen.enable_speech_enhancement')], fn ($v) => $v !== null) : [],
            'configured' => filled(config($provider === 'heygen' ? 'dubbing.heygen.key' : 'dubbing.key'))];
    }

    public function resolve(string $provider): DubbingGatewayInterface
    {
        return match ($provider) {
            'elevenlabs' => $this->elevenlabs,
            'heygen' => $this->heygen,
            default => throw new BillingException('Dubbing is not configured yet.', 503),
        };
    }

    public function languages(string $mediaType = 'video'): array
    {
        return $this->definition($mediaType)['provider'] === 'heygen' ? $this->heygen->languages() : (new DubbingLanguages)->sourceLanguages();
    }
}
