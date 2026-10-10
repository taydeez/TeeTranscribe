<?php

namespace App\Domain\Dubbing\Services;

use App\Domain\AI\Services\ProviderRouting;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Contracts\DubbingGatewayResolverInterface;

final class DubbingCatalog
{
    public function __construct(private readonly DubbingLanguages $languages, private readonly BillingSettingsInterface $settings, private readonly DubbingGatewayResolverInterface $providers, private readonly VideoSubtitleLanguages $videoSubtitles, private readonly ProviderRouting $routing) {}

    public function all(): array
    {
        $data = $this->availableLanguages('video');
        $configured = $data !== [];
        $audioData = $this->availableLanguages('audio');
        $audioConfigured = $audioData !== [];

        return ['data' => $data, 'sourceLanguages' => $this->languages->sourceLanguages(),
            'subtitleStyles' => SubtitleStyles::catalog(), 'configured' => $configured, 'subtitlesOnly' => $this->videoSubtitles->catalog(),
            'audioDubbing' => ['configured' => $audioConfigured, 'data' => $audioData, 'sourceLanguages' => $this->languages->sourceLanguages()]];
    }

    private function availableLanguages(string $mediaType): array
    {
        $items = [];
        $priced = [];
        $definitions = [];
        $configuration = $this->routing->configuration($mediaType === 'audio' ? 'audio_dubbing' : 'video_dubbing')['configuration'];
        try {
            $languages = $this->languages->all($mediaType);
            foreach ($languages as $language) {
                $provider = ProviderRouting::providerFor($configuration, DubbingLanguages::routingCode($language['code']));
                $model = ProviderRouting::modelFor($configuration, DubbingLanguages::routingCode($language['code']));
                $selection = $definitions[$provider.':'.$model] ??= $this->providers->definition($mediaType, $language['code']);
                $key = $selection['provider'].':'.$selection['model'];
                if (! array_key_exists($key, $priced)) {
                    try {
                        $this->settings->rate($mediaType === 'audio' ? 'audio_dubbing' : 'video_dubbing', $selection['provider'], $selection['model']);
                        $priced[$key] = true;
                    } catch (BillingException) {
                        $priced[$key] = false;
                    }
                }
                if ($priced[$key]) {
                    $items[] = $language;
                }
            }
        } catch (BillingException) {
            return [];
        }

        return $items;
    }
}
