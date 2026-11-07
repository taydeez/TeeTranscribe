<?php

use App\Domain\AI\Contracts\ProviderCatalogInterface;
use App\Domain\AI\Contracts\ProviderConfigurationRepositoryInterface;
use App\Domain\AI\Services\FeatureAvailability;
use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['read_cache.enabled' => false]);
    Queue::fake();
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
});

function setFeature(object $test, string $feature, bool $enabled): void
{
    $repository = app(ProviderConfigurationRepositoryInterface::class);
    $record = $repository->find($feature);
    $configuration = $record['configuration'] ?? app(ProviderCatalogInterface::class)->defaults($feature);
    $configuration['enabled'] = $enabled;
    $repository->save($feature, $configuration, $record['version'] ?? 0, $test->user->id, 'Test feature availability');
}

test('public feature catalog exposes only five boolean switches with backward compatible defaults', function () {
    $expected = array_fill_keys(FeatureAvailability::FEATURES, true);
    $this->getJson('/api/v1/features')->assertOk()->assertExactJson(['data' => $expected])->assertHeader('Cache-Control', 'no-store, private');
    setFeature($this, 'translation', false);
    $expected['translation'] = false;
    $this->getJson('/api/v1/features')->assertExactJson(['data' => $expected]);
});

test('disabled text features reject new quotes and submissions without enqueuing work', function (string $feature, string $quotePath, string $submitPath, array $body) {
    setFeature($this, $feature, false);
    Sanctum::actingAs($this->user);
    $this->postJson($quotePath, $body + ['client_key' => (string) Str::uuid()])->assertForbidden();
    $this->postJson($submitPath, ['quote_id' => (string) Str::ulid()])->assertForbidden();
    if ($feature === 'translation') {
        $this->patchJson('/api/v1/translations/'.(string) Str::ulid(), ['translated_text' => 'Changed text'])->assertForbidden();
    }
    $this->assertDatabaseCount('billing_quotes', 0);
    $this->assertDatabaseCount('outbox_events', 0);
    Http::assertNothingSent();
})->with([
    ['transcription', '/api/v1/billing/quotes', '/api/v1/transcribe', ['audio_url' => 'https://example.com/audio.mp3', 'language_code' => 'en']],
    ['translation', '/api/v1/translations/quotes', '/api/v1/translations', ['text' => 'Hello world', 'target_language' => 'fr', 'source_language' => 'en']],
]);

test('each media feature and subtitle add-on is enforced independently', function (string $feature, array $source) {
    setFeature($this, $feature, false);
    Sanctum::actingAs($this->user);
    $this->postJson('/api/v1/dubbings/quotes', $source + ['client_key' => (string) Str::uuid(), 'target_language' => 'fr'])->assertForbidden();
    $this->assertDatabaseCount('billing_quotes', 0);
    Http::assertNothingSent();
})->with([
    ['audio_dubbing', ['media_type' => 'audio', 'audio_storage_path' => 'audio/source.mp3']],
    ['video_dubbing', ['media_type' => 'video', 'video_storage_path' => 'audio/source.mp4']],
    ['subtitles', ['operation' => 'subtitles', 'video_storage_path' => 'audio/source.mp4']],
    ['subtitles', ['subtitles_enabled' => true, 'video_storage_path' => 'audio/source.mp4']],
]);

test('a ready media quote cannot bypass a switch disabled after quoting', function (string $feature, array $source) {
    $quote = app(BillingRepositoryInterface::class)->createQuote(['user_id' => $this->user->id,
        'client_key' => (string) Str::uuid(), 'activity' => 'dubbing', 'provider' => 'elevenlabs', 'model' => 'dubbing_v2',
        'source' => $source, 'request_source' => $source, 'rate' => [], 'status' => 'ready', 'expires_at' => now()->addHour()]);
    setFeature($this, $feature, false);
    Sanctum::actingAs($this->user);
    $this->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertForbidden();
    $this->assertDatabaseCount('dubbings', 0);
    $this->assertDatabaseCount('credit_transactions', 0);
})->with([
    ['audio_dubbing', ['media_type' => 'audio', 'audio_storage_path' => 'audio/source.mp3']],
    ['video_dubbing', ['media_type' => 'video', 'video_storage_path' => 'audio/source.mp4']],
    ['subtitles', ['operation' => 'subtitles', 'video_storage_path' => 'audio/source.mp4']],
]);

test('disabled dubbing blocks export retries while saved records remain readable', function () {
    $record = Dubbing::factory()->create(['user_id' => $this->user->id, 'status' => 'failed', 'provider_completed_at' => now()]);
    setFeature($this, 'video_dubbing', false);
    Sanctum::actingAs($this->user);
    $this->postJson('/api/v1/dubbings/'.$record->id.'/retry')->assertForbidden();
    $this->getJson('/api/v1/dubbings/'.$record->id)->assertOk();
    expect($record->fresh()->status)->toBe('failed');
});

test('admins can disable and reenable a feature with an audit trail and older clients preserve its switch', function () {
    $this->seed(PermissionsSeeder::class);
    $this->user->assignRole('super_admin');
    $token = app(AuthRepositoryInterface::class)->issueToken($this->user->id, 'admin');
    $configuration = app(ProviderCatalogInterface::class)->defaults('translation');
    $configuration['enabled'] = false;
    $this->withToken($token)->putJson('/api/v1/admin/ai-providers/translation', ['version' => 0, 'reason' => 'Temporarily disable translation', 'configuration' => $configuration])
        ->assertOk()->assertJsonPath('data.configuration.enabled', false);
    unset($configuration['enabled']);
    $this->putJson('/api/v1/admin/ai-providers/translation', ['version' => 1, 'reason' => 'Older client updates routing', 'configuration' => $configuration])
        ->assertOk()->assertJsonPath('data.configuration.enabled', false);
    $configuration['enabled'] = true;
    $this->putJson('/api/v1/admin/ai-providers/translation', ['version' => 2, 'reason' => 'Enable translation again', 'configuration' => $configuration])
        ->assertOk()->assertJsonPath('data.configuration.enabled', true);
    $this->getJson('/api/v1/features')->assertJsonPath('data.translation', true);
    $this->assertDatabaseCount('ai_provider_configuration_changes', 3);
});
