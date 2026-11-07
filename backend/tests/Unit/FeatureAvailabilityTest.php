<?php

use App\Domain\AI\Contracts\ProviderCatalogInterface;
use App\Domain\AI\Contracts\ProviderConfigurationRepositoryInterface;
use App\Domain\AI\Services\FeatureAvailability;
use App\Domain\AI\Services\ProviderRouting;
use App\Domain\Billing\Exceptions\BillingException;

function availabilityFor(array $flags): FeatureAvailability
{
    $repository = Mockery::mock(ProviderConfigurationRepositoryInterface::class);
    $catalog = Mockery::mock(ProviderCatalogInterface::class);
    $catalog->shouldReceive('activities')->andReturn(array_fill_keys(FeatureAvailability::FEATURES, 'Feature'));
    foreach (FeatureAvailability::FEATURES as $feature) {
        $repository->shouldReceive('find')->with($feature)->andReturn(['configuration' => ['enabled' => $flags[$feature] ?? true]]);
    }

    return new FeatureAvailability(new ProviderRouting($repository, $catalog));
}

test('audio dubbing and standalone subtitles do not depend on the video dubbing switch', function () {
    $features = availabilityFor(['video_dubbing' => false, 'transcription' => false, 'translation' => false]);
    $features->requireDubbing(['media_type' => 'audio']);
    $features->requireDubbing(['operation' => 'subtitles']);
    expect(fn () => $features->requireDubbing([]))->toThrow(BillingException::class);
});

test('disabling subtitles still allows uncaptioned video dubbing', function () {
    $features = availabilityFor(['subtitles' => false]);
    $features->requireDubbing(['media_type' => 'video', 'subtitles_enabled' => false]);
    expect(fn () => $features->requireDubbing(['subtitles_enabled' => true]))->toThrow(BillingException::class);
});
