<?php

use App\Domain\Admin\AIProvider\Services\AIProviderConfigurationService;
use App\Domain\AI\Contracts\ProviderCatalogInterface;
use App\Domain\AI\Services\ProviderRouting;
use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\UsageQuoteService;
use App\Domain\Dubbing\Contracts\DubbingGatewayResolverInterface;
use App\Domain\Dubbing\Services\DubbingLanguages;
use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;
use App\Domain\Translation\Contracts\TranslationGatewayResolverInterface;
use App\Infrastructure\AI\Transcriber\DeepGram\DeepGramClient;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['transcriber.deepgram.key' => 'secret-never-expose-deepgram', 'transcriber.deepgram.model' => 'nova-2',
        'transcriber.fallback' => 'deepgram', 'transcriber.language_providers' => [],
        'transcriber.intron.key' => 'secret-intron', 'transcriber.intron.languages' => ['en-NG', 'yo', 'ig', 'ha', 'pcm'],
        'openai.key' => 'secret-openai', 'translation.google.key' => 'secret-google', 'translation.provider' => 'google',
        'dubbing.key' => 'secret-elevenlabs', 'dubbing.heygen.key' => 'secret-heygen', 'dubbing.provider' => 'elevenlabs',
        'dubbing.audio_provider' => 'elevenlabs', 'dubbing.heygen.mode' => 'precision',
        'billing.rates.transcription.deepgram' => ['nova-2' => ['unit' => 'minute', 'credits' => '10'], 'nova-3' => ['unit' => 'minute', 'credits' => '15']]]);
    $this->seed(PermissionsSeeder::class);
    Bus::fake();
    Http::preventStrayRequests();
});

function providerAdmin(string $role = 'super_admin'): array
{
    $user = User::factory()->create()->assignRole($role);

    return [$user, app(AuthRepositoryInterface::class)->issueToken($user->id, 'admin')];
}

function providerSettings(string $activity): array
{
    return ['version' => 0, 'reason' => 'Update routing for this activity', 'configuration' => app(ProviderCatalogInterface::class)->defaults($activity)];
}

test('only administrators with provider permissions can read and change settings', function () {
    $this->getJson('/api/v1/admin/ai-providers')->assertUnauthorized();
    $user = User::factory()->create();
    $this->withToken(app(AuthRepositoryInterface::class)->issueToken($user->id, 'web'))->getJson('/api/v1/admin/ai-providers')->assertForbidden();
    app('auth')->forgetGuards();
    [, $token] = providerAdmin('admin');
    $this->withToken($token)->getJson('/api/v1/admin/ai-providers')->assertForbidden();
    $this->putJson('/api/v1/admin/ai-providers/transcription', providerSettings('transcription'))->assertForbidden();
});

test('provider catalog shows environment defaults and credential status without leaking secrets', function () {
    [, $token] = providerAdmin();
    $response = $this->withToken($token)->getJson('/api/v1/admin/ai-providers')->assertOk()->assertJsonCount(7, 'data')
        ->assertJsonPath('data.0.configuration.default_provider', 'deepgram')
        ->assertJsonPath('data.0.catalog.deepgram.configured', true);
    expect($response->getContent())->not->toContain('secret-never-expose-deepgram')->not->toContain('secret-openai');
});

test('saving rules is audited and exact language rules take priority over base language rules', function () {
    [$actor, $token] = providerAdmin();
    $input = providerSettings('transcription');
    $input['configuration']['language_rules']['yo-ng'] = 'deepgram';
    $this->withToken($token)->putJson('/api/v1/admin/ai-providers/transcription', $input)->assertOk()
        ->assertJsonPath('data.version', 1)->assertJsonPath('data.history.0.actorId', $actor->id)
        ->assertJsonPath('data.history.0.before.default_provider', 'deepgram');
    $routing = app(ProviderRouting::class);
    expect($routing->select('transcription', 'yo-NG')['provider'])->toBe('deepgram');
    expect($routing->select('transcription', 'yo')['provider'])->toBe('intron');
    expect($routing->select('transcription', 'fr')['provider'])->toBe('deepgram');
    $this->putJson('/api/v1/admin/ai-providers/transcription', $input)->assertConflict();
    expect(DB::table('ai_provider_configuration_changes')->count())->toBe(1);
});

test('disabled or unconfigured providers cannot create new quotes but queued requests retain their provider', function () {
    [$actor] = providerAdmin();
    $input = providerSettings('transcription');
    $input['configuration']['providers']['deepgram']['enabled'] = false;
    app(AIProviderConfigurationService::class)->update('transcription', $input, $actor->id);
    expect(fn () => app(UsageQuoteService::class)->create($actor->id, ['audio_url' => 'https://example.test/audio.mp3', 'language_code' => 'fr'], 'unavailable'))
        ->toThrow(BillingException::class);
    expect(DB::table('billing_quotes')->count())->toBe(0);
    expect(app(TranscriberGatewayResolverInterface::class)->resolve('fr', 'deepgram')->provider())->toBe('deepgram');
    config(['transcriber.intron.key' => null]);
    expect(fn () => app(ProviderRouting::class)->select('transcription', 'yo'))->toThrow(BillingException::class);
});

test('transcription quotes snapshot provider model and price across configuration changes', function () {
    [$actor] = providerAdmin();
    $source = ['audio_url' => 'https://example.test/audio.mp3', 'language_code' => 'fr'];
    $quotes = app(UsageQuoteService::class);
    $old = $quotes->create($actor->id, $source, 'before');
    $input = providerSettings('transcription');
    $input['configuration']['providers']['deepgram']['model'] = 'nova-3';
    app(AIProviderConfigurationService::class)->update('transcription', $input, $actor->id);
    $new = $quotes->create($actor->id, $source, 'after');
    expect($old['model'])->toBe('nova-2')->and($new['model'])->toBe('nova-3');
    expect($old['rate']['credit_units'])->not->toBe($new['rate']['credit_units']);
    expect($quotes->create($actor->id, $source, 'before')['model'])->toBe('nova-2');
});

test('translation and video dubbing select by output language while audio defaults stay independent', function () {
    [$actor] = providerAdmin();
    $input = providerSettings('translation');
    $input['configuration']['language_rules'] = ['yo' => 'openai'];
    app(AIProviderConfigurationService::class)->update('translation', $input, $actor->id);
    expect(app(TranslationGatewayResolverInterface::class)->definition('yo')['provider'])->toBe('openai');
    expect(app(TranslationGatewayResolverInterface::class)->definition('fr')['provider'])->toBe('google');
    $input = providerSettings('video_dubbing');
    $input['configuration']['language_rules'] = ['es' => 'heygen'];
    $input['configuration']['providers']['heygen']['model'] = 'speed';
    app(AIProviderConfigurationService::class)->update('video_dubbing', $input, $actor->id);
    $providers = app(DubbingGatewayResolverInterface::class);
    expect($providers->definition('video', 'es'))->toMatchArray(['provider' => 'heygen', 'model' => 'speed']);
    expect($providers->definition('audio', 'es')['provider'])->toBe('elevenlabs');
});

test('language specific providers remain selectable when the default is disabled', function () {
    [$actor] = providerAdmin();
    $input = providerSettings('translation');
    $input['configuration']['providers']['google']['enabled'] = false;
    $input['configuration']['language_rules'] = ['yo' => 'openai'];
    app(AIProviderConfigurationService::class)->update('translation', $input, $actor->id);
    $providers = app(TranslationGatewayResolverInterface::class);
    expect(array_column($providers->languages(), 'code'))->toBe(['yo']);
    expect($providers->definition('yo')['provider'])->toBe('openai');
    Http::assertNothingSent();

    $input = providerSettings('video_dubbing');
    $input['configuration']['providers']['elevenlabs']['enabled'] = false;
    $input['configuration']['language_rules'] = ['es' => 'heygen'];
    app(AIProviderConfigurationService::class)->update('video_dubbing', $input, $actor->id);
    Http::fake(['api.heygen.com/v3/video-translations/languages' => Http::response(['data' => ['languages' => ['English', 'Spanish']]])]);
    $providers = app(DubbingGatewayResolverInterface::class);
    expect(array_column($providers->languages(), 'code'))->toBe(['Spanish']);
    expect($providers->definition('video', 'Spanish')['provider'])->toBe('heygen');
    expect(DubbingLanguages::routingCode('Spanish'))->toBe('es');
});

test('administrators add priced models and route languages without changing existing quotes', function () {
    [$actor, $token] = providerAdmin();
    $quotes = app(UsageQuoteService::class);
    $source = ['audio_url' => 'https://example.test/audio.mp3', 'language_code' => 'fr'];
    $old = $quotes->create($actor->id, $source, 'original-model');
    $configuration = app(AIProviderConfigurationService::class)->record('transcription')['configuration'];
    $model = $configuration['models']['deepgram'][0];
    $model['id'] = 'custom-batch-v1.2';
    $model['label'] = 'French interviews';
    $model['languages'] = ['fr'];
    $model['pricing'] = ['unit' => 'minute', 'credits' => '25.50', 'provider_cost' => '0.006', 'provider_currency' => 'USD'];
    $configuration['models']['deepgram'][] = $model;
    $configuration['language_rules']['fr'] = ['provider' => 'deepgram', 'model' => $model['id']];
    $this->withToken($token)->putJson('/api/v1/admin/ai-providers/transcription', [
        'version' => 0, 'reason' => 'Add the interview model', 'configuration' => $configuration,
    ])->assertOk()->assertJsonPath('data.configuration.models.deepgram.2.label', 'French interviews');
    $new = $quotes->create($actor->id, $source, 'custom-model');
    expect($new['model'])->toBe($model['id'])->and($new['rate']['credit_units'])->toBe(2550)
        ->and($new['rate']['provider_cost_micros'])->toBe(6000);
    expect($old['model'])->toBe('nova-2')->and($old['rate']['credit_units'])->toBe(1000);
    expect($quotes->create($actor->id, $source, 'original-model')['rate'])->toBe($old['rate']);
    expect(app(ProviderRouting::class)->select('transcription', 'fr-CA')['model'])->toBe($model['id']);
    expect(app(ProviderRouting::class)->select('transcription', 'en')['model'])->toBe('nova-2');
    expect(DB::table('ai_provider_configuration_changes')->count())->toBe(1);
    Http::fake(['api.deepgram.com/*' => Http::response(['request_id' => 'provider-custom-model'])]);
    app(DeepGramClient::class)->transcribe($source['audio_url'], 'fr', (string) Str::ulid(), $new['model']);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'model=custom-batch-v1.2'));
    $configuration['models']['deepgram'][2]['pricing']['credits'] = '30';
    $this->putJson('/api/v1/admin/ai-providers/transcription', [
        'version' => 1, 'reason' => 'Revise this model price', 'configuration' => $configuration,
    ])->assertOk();
    expect($quotes->create($actor->id, $source, 'repriced')['rate']['credit_units'])->toBe(3000);
    expect($quotes->create($actor->id, $source, 'custom-model')['rate']['credit_units'])->toBe(2550);
});

test('disabling a model prevents new quotes while old quotes and explicit providers remain usable', function () {
    [$actor] = providerAdmin();
    $source = ['audio_url' => 'https://example.test/audio.mp3', 'language_code' => 'fr'];
    $quotes = app(UsageQuoteService::class);
    $old = $quotes->create($actor->id, $source, 'before-disable');
    $configuration = app(AIProviderConfigurationService::class)->record('transcription')['configuration'];
    $configuration['models']['deepgram'][0]['enabled'] = false;
    app(AIProviderConfigurationService::class)->update('transcription', ['version' => 0, 'reason' => 'Retire this model', 'configuration' => $configuration], $actor->id);
    expect(fn () => $quotes->create($actor->id, $source, 'after-disable'))->toThrow(BillingException::class);
    expect($quotes->create($actor->id, $source, 'before-disable')['model'])->toBe($old['model']);
    expect(app(TranscriberGatewayResolverInterface::class)->resolve('fr', 'deepgram')->provider())->toBe('deepgram');
    expect(DB::table('billing_quotes')->count())->toBe(1);
});

test('model rules filter translation languages and use saved catalog pricing', function () {
    [$actor] = providerAdmin();
    $configuration = app(AIProviderConfigurationService::class)->record('translation')['configuration'];
    $configuration['providers']['google']['enabled'] = false;
    $model = $configuration['models']['openai'][0];
    $model['id'] = 'custom-text-v2';
    $model['languages'] = ['yo'];
    $model['pricing']['credits'] = '12';
    $configuration['models']['openai'][] = $model;
    $configuration['language_rules'] = ['yo' => ['provider' => 'openai', 'model' => $model['id']]];
    app(AIProviderConfigurationService::class)->update('translation', ['version' => 0, 'reason' => 'Use a model for Yoruba', 'configuration' => $configuration], $actor->id);
    $resolver = app(TranslationGatewayResolverInterface::class);
    expect($resolver->definition('yo')['model'])->toBe('custom-text-v2');
    expect(array_column($resolver->languages(), 'code'))->toBe(['yo']);
    expect(app(BillingSettingsInterface::class)->rate('translation', 'openai', 'custom-text-v2')['credit_units'])->toBe(1200);
    Http::assertNothingSent();
});
