<?php

use App\Domain\AI\Contracts\ProviderCatalogInterface;
use App\Domain\AI\Contracts\ProviderConfigurationRepositoryInterface;
use App\Domain\AI\Services\ProviderRouting;
use App\Domain\Billing\Exceptions\BillingException;

test('routing chooses exact code then base code then default and respects restrictions', function () {
    $repository = Mockery::mock(ProviderConfigurationRepositoryInterface::class);
    $catalog = Mockery::mock(ProviderCatalogInterface::class);
    $catalog->shouldReceive('activities')->andReturn(['transcription' => 'Transcription']);
    $catalog->shouldReceive('providers')->with('transcription')->andReturn([
        'a' => ['configured' => true, 'languages' => []], 'b' => ['configured' => true, 'languages' => []],
    ]);
    $repository->shouldReceive('find')->with('transcription')->andReturn(['configuration' => [
        'default_provider' => 'a', 'language_rules' => ['en' => 'b', 'en-us' => 'a'],
        'providers' => ['a' => ['enabled' => true, 'model' => 'a1', 'languages' => ['en', 'fr']],
            'b' => ['enabled' => true, 'model' => 'b1', 'languages' => ['en']]],
    ]]);
    $routing = new ProviderRouting($repository, $catalog);
    expect($routing->select('transcription', 'en-US'))->toBe(['provider' => 'a', 'model' => 'a1']);
    expect($routing->select('transcription', 'en-GB')['provider'])->toBe('b');
    expect($routing->select('transcription', 'fr')['provider'])->toBe('a');
    expect(fn () => $routing->select('transcription', 'de'))->toThrow(BillingException::class);
});

test('regional language support does not silently grant all variants', function () {
    expect(ProviderRouting::supports(['en-NG'], 'en-us'))->toBeFalse();
    expect(ProviderRouting::supports(['en'], 'en-us'))->toBeTrue();
    expect(ProviderRouting::supports(['yo'], 'yo-ng'))->toBeTrue();
});

test('model overrides follow exact and base rules and otherwise use provider defaults', function () {
    $configuration = ['default_provider' => 'a', 'providers' => ['a' => ['model' => 'a1'], 'b' => ['model' => 'b1']],
        'language_rules' => ['en' => ['provider' => 'a', 'model' => 'a2'], 'en-ng' => ['provider' => 'b', 'model' => 'b2'], 'fr' => 'b']];
    expect(ProviderRouting::modelFor($configuration, 'en-NG'))->toBe('b2');
    expect(ProviderRouting::modelFor($configuration, 'en-US'))->toBe('a2');
    expect(ProviderRouting::modelFor($configuration, 'fr'))->toBe('b1');
    expect(ProviderRouting::modelFor($configuration, 'es'))->toBe('a1');
});
