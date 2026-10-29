<?php

use App\Domain\Admin\Billing\Contracts\BillingConfigurationRepositoryInterface;
use App\Domain\AI\Contracts\ProviderCatalogInterface;
use App\Domain\AI\Contracts\ProviderConfigurationRepositoryInterface;
use App\Domain\Billing\Contracts\BillingConfigurationInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Infrastructure\AI\Dubbing\HeyGen\HeyGenDubbingGateway;
use App\Infrastructure\AI\Translation\Gateways\GoogleTranslationGateway;
use App\Infrastructure\Cache\ReadCache;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(DatabaseMigrations::class);

beforeEach(function () {
    config(['read_cache.enabled' => true, 'read_cache.store' => 'array', 'read_cache.settings_seconds' => 300,
        'read_cache.languages_seconds' => 86400, 'billing.fx.ngn_per_usd' => '1500',
        'billing.fx.updated_at' => now()->toIso8601String(), 'billing.fx.max_age_hours' => 24]);
    Cache::store('array')->flush();
    Http::preventStrayRequests();
});

test('settings reads including missing settings are cached and saves invalidate after commit', function (string $type) {
    $actor = User::factory()->create();
    $repository = app($type === 'billing' ? BillingConfigurationRepositoryInterface::class : ProviderConfigurationRepositoryInterface::class);
    $read = fn () => $type === 'billing' ? $repository->find() : $repository->find('transcription');
    $configuration = $type === 'billing' ? app(BillingConfigurationInterface::class)->defaults() : app(ProviderCatalogInterface::class)->defaults('transcription');
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($read())->toBeNull()->and($read())->toBeNull();
    expect(DB::getQueryLog())->toHaveCount(1);
    if ($type === 'billing') {
        $repository->save($configuration, $configuration, 0, $actor->id, 'Initial billing configuration');
    } else {
        $repository->save('transcription', $configuration, 0, $actor->id, 'Initial provider configuration');
    }
    DB::flushQueryLog();
    expect($read()['version'])->toBe(1)->and($read()['configuration'])->toBe($configuration);
    expect(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
})->with(['billing', 'provider']);

test('rollback preserves the committed cache and transaction reads cannot publish uncommitted settings', function () {
    $actor = User::factory()->create();
    $repository = app(BillingConfigurationRepositoryInterface::class);
    $configuration = app(BillingConfigurationInterface::class)->defaults();
    $repository->save($configuration, $configuration, 0, $actor->id, 'Initial settings');
    expect($repository->find()['version'])->toBe(1);
    DB::beginTransaction();
    $configuration['free_credits'] = '50';
    $repository->save($configuration, [], 1, $actor->id, 'Rolled back settings');
    expect($repository->find()['version'])->toBe(2);
    DB::rollBack();
    expect($repository->find()['version'])->toBe(1)->and($repository->find()['configuration']['free_credits'])->not->toBe('50');
    DB::beginTransaction();
    $repository->save($configuration, [], 1, $actor->id, 'Committed settings');
    expect($repository->find()['version'])->toBe(2);
    DB::commit();
    expect($repository->find()['version'])->toBe(2)->and($repository->find()['configuration']['free_credits'])->toBe('50');
});

test('an in flight read cannot repopulate the current cache generation after invalidation', function () {
    $cache = app(ReadCache::class);
    $old = $cache->remember('race', 300, function () use ($cache) {
        $cache->invalidate('race');
        expect($cache->remember('race', 300, fn () => 'new'))->toBe('new');

        return 'old';
    });
    expect($old)->toBe('old')->and($cache->remember('race', 300, fn () => 'unexpected'))->toBe('new');
});

test('settings expire and cached exchange rates still undergo freshness checks', function () {
    $cache = app(ReadCache::class);
    expect($cache->remember('expiry', 10, fn () => 'first'))->toBe('first');
    $this->travel(11)->seconds();
    expect($cache->remember('expiry', 10, fn () => 'second'))->toBe('second');
    $actor = User::factory()->create();
    $configuration = app(BillingConfigurationInterface::class)->defaults();
    $configuration['fx']['max_age_hours'] = 1;
    app(BillingConfigurationRepositoryInterface::class)->save($configuration, $configuration, 0, $actor->id, 'FX settings');
    config(['read_cache.settings_seconds' => 7200]);
    expect(app(BillingSettingsInterface::class)->fx()['ngn_per_usd_micros'])->toBe(1500000000);
    $this->travel(61)->minutes();
    expect(fn () => app(BillingSettingsInterface::class)->fx())->toThrow(BillingException::class, 'The currency quote needs to be refreshed.');
});

test('language catalogs reuse provider results and expire independently of settings', function () {
    config(['translation.google.key' => 'google-test', 'dubbing.heygen.key' => 'heygen-test', 'read_cache.languages_seconds' => 10]);
    Http::fake(['translation.googleapis.com/*' => Http::response(['data' => ['languages' => [['language' => 'yo', 'name' => 'Yoruba']]]]),
        'api.heygen.com/*' => Http::response(['data' => ['languages' => ['Spanish']]])]);
    foreach ([GoogleTranslationGateway::class, HeyGenDubbingGateway::class] as $gateway) {
        expect(app($gateway)->languages())->not->toBeEmpty();
        expect(app($gateway)->languages())->not->toBeEmpty();
    }
    Http::assertSentCount(2);
    $this->travel(11)->seconds();
    app(GoogleTranslationGateway::class)->languages();
    app(HeyGenDubbingGateway::class)->languages();
    Http::assertSentCount(4);
});

test('cache outages fall back to the source without swallowing or retrying source failures', function () {
    $broken = Mockery::mock(Repository::class);
    $broken->shouldReceive('get')->andThrow(new RuntimeException('Redis unavailable'));
    $broken->shouldReceive('put')->andThrow(new RuntimeException('Redis unavailable'));
    Cache::shouldReceive('store')->with('array')->andReturn($broken);
    $cache = app(ReadCache::class);
    expect($cache->remember('outage', 300, fn () => ['fresh' => true]))->toBe(['fresh' => true]);
    $cache->invalidate('outage');
    $calls = 0;
    $operation = function () use ($cache, &$calls) {
        return $cache->remember('source-error', 300, function () use (&$calls) {
            $calls++;
            throw new RuntimeException('Source failed');
        });
    };
    expect($operation)->toThrow(RuntimeException::class, 'Source failed');
    expect($calls)->toBe(1);
});

test('a cache write failure returns the already fetched result without reloading the source', function () {
    $broken = Mockery::mock(Repository::class);
    $broken->shouldReceive('get')->with('reads:v1:write-failure:generation')->andReturn('token');
    $broken->shouldReceive('get')->with('reads:v1:write-failure:token')->andReturnNull();
    $broken->shouldReceive('put')->andThrow(new RuntimeException('Redis unavailable'));
    Cache::shouldReceive('store')->with('array')->andReturn($broken);
    $calls = 0;
    expect(app(ReadCache::class)->remember('write-failure', 300, function () use (&$calls) {
        $calls++;

        return 'fresh';
    }))->toBe('fresh');
    expect($calls)->toBe(1);
});

test('read caching uses its configured store without clearing unrelated cache entries or locks', function () {
    config(['cache.stores.reads_test' => ['driver' => 'array', 'serialize' => false], 'read_cache.store' => 'reads_test']);
    Cache::store('array')->put('unrelated-entry', 'keep', 300);
    $lock = Cache::store('array')->lock('critical-billing-operation', 60);
    expect($lock->get())->toBeTrue();
    $cache = app(ReadCache::class);
    expect($cache->remember('settings', 300, fn () => 'old'))->toBe('old');
    $cache->invalidate('settings');
    expect($cache->remember('settings', 300, fn () => 'new'))->toBe('new')
        ->and(Cache::store('array')->get('unrelated-entry'))->toBe('keep')
        ->and(Cache::store('array')->lock('critical-billing-operation', 60)->get())->toBeFalse();
    $lock->release();
});
