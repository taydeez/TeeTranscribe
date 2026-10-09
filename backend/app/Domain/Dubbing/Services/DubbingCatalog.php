<?php

namespace App\Domain\Dubbing\Services;

use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Contracts\DubbingGatewayResolverInterface;

final class DubbingCatalog
{
    public function __construct(private readonly DubbingLanguages $languages, private readonly BillingSettingsInterface $settings, private readonly DubbingGatewayResolverInterface $providers, private readonly VideoSubtitleLanguages $videoSubtitles) {}

    public function all(): array
    {
        $configured = false;
        $data = [];
        try {
            $definition = $this->providers->definition();
            $configured = $definition['configured'];
            $this->settings->rate('dubbing', $definition['provider'], $definition['model']);
            $data = $configured ? $this->languages->all() : [];
        } catch (BillingException) {
            $configured = false;
        }
        $audioConfigured = false;
        $audioData = [];
        try {
            $definition = $this->providers->definition('audio');
            $this->settings->rate('dubbing', $definition['provider'], $definition['model']);
            $audioConfigured = $definition['configured'];
            $audioData = $audioConfigured ? $this->languages->all('audio') : [];
        } catch (BillingException) {
            $audioConfigured = false;
        }

        return ['data' => $data, 'sourceLanguages' => $this->languages->sourceLanguages(),
            'subtitleStyles' => SubtitleStyles::catalog(), 'configured' => $configured, 'subtitlesOnly' => $this->videoSubtitles->catalog(),
            'audioDubbing' => ['configured' => $audioConfigured, 'data' => $audioData, 'sourceLanguages' => $this->languages->sourceLanguages()]];
    }
}
