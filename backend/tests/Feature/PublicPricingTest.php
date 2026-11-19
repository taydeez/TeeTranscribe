<?php

use App\Domain\Admin\Billing\Contracts\BillingConfigurationRepositoryInterface;
use App\Domain\AI\Contracts\ProviderCatalogInterface;
use App\Domain\AI\Contracts\ProviderConfigurationRepositoryInterface;
use App\Domain\Billing\Contracts\BillingConfigurationInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['read_cache.enabled' => false, 'billing.free_credits' => '10',
        'billing.packages' => ['starter' => ['name' => 'Starter', 'credits' => '500', 'price_usd' => '5.00']],
        'transcriber.fallback' => 'deepgram', 'transcriber.deepgram.model' => 'nova-2', 'transcriber.deepgram.key' => 'private-provider-secret',
        'translation.provider' => 'google', 'translation.google.key' => 'private-translation-key',
        'billing.rates.transcription.deepgram.nova-2' => ['unit' => 'minute', 'credits' => '2', 'provider_cost' => '0.0043', 'provider_currency' => 'USD'],
        'billing.rates.translation.google.nmt' => ['unit' => 'character', 'credits' => '0.01', 'provider_cost' => '0.00002', 'provider_currency' => 'USD']]);
    Http::preventStrayRequests();
});

test('public pricing exposes current USD packages and normalized rates without credentials or account data', function () {
    $response = $this->getJson('/api/v1/public/pricing')->assertOk()
        ->assertJsonPath('data.packages.0.amount_minor', 500)->assertJsonPath('data.packages.0.credit_units', 50000)
        ->assertJsonPath('data.free_credit_units', 1000);
    $data = $response->json('data');
    $translation = collect($data['rates'])->firstWhere('activity', 'translation');
    expect($translation)->toBe(['activity' => 'translation', 'unit' => '1000_characters', 'credit_units' => 1000]);
    expect($response->getContent())->not->toContain('private-provider-secret')->not->toContain('private-translation-key')
        ->not->toContain('provider_cost')->not->toContain('customer_email')->not->toContain('callback_url');
    $this->assertDatabaseCount('credit_wallets', 0);
    $this->assertDatabaseCount('payments', 0);
    Http::assertNothingSent();
});

test('public pricing reflects saved discounts and hides disabled packages and activities', function () {
    $actor = User::factory()->create();
    $configuration = app(BillingConfigurationInterface::class)->current();
    $configuration['packages'] = [['code' => 'discount', 'name' => 'Bundle', 'credits' => '600', 'price_usd' => '4.75', 'enabled' => true],
        ['code' => 'hidden', 'name' => 'Hidden', 'credits' => '1000', 'price_usd' => '8.00', 'enabled' => false]];
    app(BillingConfigurationRepositoryInterface::class)->save($configuration, [], 0, $actor->id, 'Offer a discounted bundle');
    $feature = app(ProviderCatalogInterface::class)->defaults('translation');
    $feature['enabled'] = false;
    app(ProviderConfigurationRepositoryInterface::class)->save('translation', $feature, 0, $actor->id, 'Disable translation');
    $response = $this->getJson('/api/v1/public/pricing')->assertOk()->assertJsonCount(1, 'data.packages')
        ->assertJsonPath('data.packages.0.amount_minor', 475)->assertJsonPath('data.packages.0.credit_units', 60000);
    expect(array_column($response->json('data.rates'), 'activity'))->not->toContain('translation');
});
