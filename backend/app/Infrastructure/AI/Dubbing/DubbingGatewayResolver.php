<?php

namespace App\Infrastructure\AI\Dubbing;

use App\Domain\AI\Services\ProviderRouting;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Contracts\DubbingGatewayInterface;
use App\Domain\Dubbing\Contracts\DubbingGatewayResolverInterface;
use App\Domain\Dubbing\Services\DubbingLanguages;
use App\Infrastructure\AI\Dubbing\ElevenLabs\ElevenLabsDubbingGateway;
use App\Infrastructure\AI\Dubbing\HeyGen\HeyGenDubbingGateway;

final readonly class DubbingGatewayResolver implements DubbingGatewayResolverInterface
{
    public function __construct(private ElevenLabsDubbingGateway $elevenlabs, private HeyGenDubbingGateway $heygen, private ProviderRouting $routing) {}

    public function definition(string $mediaType = 'video', ?string $language = null): array
    {
        $selection = $this->routing->select($mediaType === 'audio' ? 'audio_dubbing' : 'video_dubbing', $language === null ? null : DubbingLanguages::routingCode($language));
        $provider = $selection['provider'];
        if ($mediaType === 'audio' && $provider !== 'elevenlabs') {
            throw new BillingException('Audio dubbing is not configured yet.', 503);
        }
        $this->resolve($provider);
        $model = $selection['model'];
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

    public function languages(string $mediaType = 'video', ?string $language = null): array
    {
        if ($language !== null) {
            return $this->definition($mediaType, $language)['provider'] === 'heygen' ? $this->heygen->languages() : (new DubbingLanguages)->sourceLanguages();
        }
        $activity = $mediaType === 'audio' ? 'audio_dubbing' : 'video_dubbing';
        $configuration = $this->routing->configuration($activity)['configuration'];
        $items = [];
        foreach ($this->routing->available($activity) as $name => $settings) {
            if (! ProviderRouting::usesProvider($configuration, $name)) {
                continue;
            }
            $models = $this->routing->models($activity, $name);
            $languages = $name === 'heygen' ? $this->heygen->languages() : (new DubbingLanguages)->sourceLanguages();
            foreach ($languages as $item) {
                if (ProviderRouting::providerFor($configuration, DubbingLanguages::routingCode($item['code'])) === $name
                    && ProviderRouting::modelSupports($configuration, DubbingLanguages::routingCode($item['code']), $models)
                    && ($settings['languages'] === [] || ProviderRouting::supports($settings['languages'], DubbingLanguages::routingCode($item['code'])))) {
                    $items[$item['code']] = $item;
                }
            }
        }

        return array_values($items);
    }
}
