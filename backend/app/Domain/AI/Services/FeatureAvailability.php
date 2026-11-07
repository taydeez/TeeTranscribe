<?php

namespace App\Domain\AI\Services;

use App\Domain\Billing\Exceptions\BillingException;

final readonly class FeatureAvailability
{
    public const FEATURES = ['transcription', 'translation', 'audio_dubbing', 'video_dubbing', 'subtitles'];

    public function __construct(private ProviderRouting $routing) {}

    public function enabled(string $feature): bool
    {
        return in_array($feature, self::FEATURES, true)
            && $this->routing->configuration($feature)['configuration']['enabled'];
    }

    public function all(): array
    {
        return array_combine(self::FEATURES, array_map($this->enabled(...), self::FEATURES));
    }

    public function requireEnabled(string $feature): void
    {
        if (! $this->enabled($feature)) {
            throw new BillingException('This feature is currently unavailable.', 403);
        }
    }

    public function requireDubbing(array $source): void
    {
        $feature = ($source['operation'] ?? 'dubbing') === 'subtitles' ? 'subtitles'
            : (($source['media_type'] ?? 'video') === 'audio' ? 'audio_dubbing' : 'video_dubbing');
        $this->requireEnabled($feature);
        if (! empty($source['subtitles_enabled'])) {
            $this->requireEnabled('subtitles');
        }
    }
}
