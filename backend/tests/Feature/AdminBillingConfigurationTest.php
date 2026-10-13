<?php

use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Domain\Billing\Contracts\BillingConfigurationInterface;
use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Billing\Services\UsageQuoteService;
use App\Domain\Payment\Services\PaymentService;
use App\Infrastructure\Persistence\Eloquent\Models\Payment;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['billing.fx.ngn_per_usd' => '1500', 'billing.fx.updated_at' => now()->toIso8601String(),
        'billing.free_credits' => '0', 'transcriber.deepgram.key' => 'private-deepgram', 'transcriber.deepgram.model' => 'nova-2',
        'transcriber.fallback' => 'deepgram', 'transcriber.language_providers' => [],
        'billing.rates.transcription.deepgram.nova-2' => ['unit' => 'minute', 'credits' => '20', 'provider_cost' => '0.01', 'provider_currency' => 'USD'],
        'payment.paystack.secret' => 'sk_test_private', 'payment.paystack.enabled' => true, 'payment.paystack.usd_enabled' => true,
        'payment.flutterwave.secret' => 'FLWSECK_TEST-private-X', 'payment.flutterwave.enabled' => true, 'payment.flutterwave.usd_enabled' => true]);
    $this->seed([PermissionsSeeder::class, PaymentMethodSeeder::class]);
    Bus::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->create()->assignRole('super_admin');
    $this->token = app(AuthRepositoryInterface::class)->issueToken($this->admin->id, 'admin');
    $this->configuration = app(BillingConfigurationInterface::class)->defaults();
});

function saveBillingConfiguration($test, array $configuration, int $version = 0): void
{
    $test->withToken($test->token)->putJson('/api/v1/admin/billing/settings', [
        'version' => $version, 'reason' => 'Update billing policy', 'configuration' => $configuration,
    ])->assertOk()->assertJsonPath('data.version', $version + 1);
}

test('admin concurrency limits are audited and apply without changing existing work', function () {
    $customer = User::factory()->create();
    $record = Transcription::factory()->create(['user_id' => $customer->id, 'status' => 'pending']);
    $this->configuration['concurrency']['overall'] = 1;
    $this->configuration['concurrency']['translation'] = 1;
    saveBillingConfiguration($this, $this->configuration);
    $this->getJson('/api/v1/admin/billing/settings')->assertOk()
        ->assertJsonPath('data.configuration.concurrency.overall', 1)
        ->assertJsonPath('data.history.0.after.concurrency.overall', 1);
    expect($record->refresh()->status)->toBe('pending');
    expect(fn () => DB::transaction(fn () => app(CreditService::class)->assertConcurrency($customer->id, 'translation', 'new')))
        ->toThrow(BillingException::class, 'You already have 1 tasks');
});

test('previously saved billing settings receive concurrency defaults and old clients preserve them', function () {
    unset($this->configuration['concurrency']);
    saveBillingConfiguration($this, $this->configuration);
    $this->getJson('/api/v1/admin/billing/settings')->assertOk()->assertJsonPath('data.configuration.concurrency.overall', 3);
    $configuration = app(BillingConfigurationInterface::class)->current();
    $configuration['concurrency']['overall'] = 5;
    saveBillingConfiguration($this, $configuration, 1);
    saveBillingConfiguration($this, $this->configuration, 2);
    $this->getJson('/api/v1/admin/billing/settings')->assertOk()->assertJsonPath('data.configuration.concurrency.overall', 5);
});

test('billing administration requires an admin session and the relevant permissions', function () {
    $this->getJson('/api/v1/admin/billing/settings')->assertUnauthorized();
    $user = User::factory()->create();
    $token = app(AuthRepositoryInterface::class)->issueToken($user->id, 'web');
    $this->withToken($token)->getJson('/api/v1/admin/billing/settings')->assertForbidden();
    app('auth')->forgetGuards();
    $user->assignRole('admin');
    $token = app(AuthRepositoryInterface::class)->issueToken($user->id, 'admin');
    $this->withToken($token)->getJson('/api/v1/admin/billing/settings')->assertForbidden();
    $this->putJson('/api/v1/admin/billing/settings', [])->assertForbidden();
    $this->getJson('/api/v1/admin/billing/report')->assertForbidden();
});

test('settings use defaults and changes are versioned and audited without credentials', function () {
    $this->withToken($this->token)->getJson('/api/v1/admin/billing/settings')->assertOk()
        ->assertJsonPath('data.version', 0)->assertJsonPath('data.credit_value_ngn', '1')
        ->assertDontSee('sk_test_private')->assertDontSee('FLWSECK_TEST-private-X');
    $this->configuration['checkout_minutes'] = 60;
    saveBillingConfiguration($this, $this->configuration);
    $this->getJson('/api/v1/admin/billing/settings')->assertJsonPath('data.history.0.actorId', $this->admin->id)
        ->assertJsonPath('data.history.0.reason', 'Update billing policy')
        ->assertJsonPath('data.history.0.before.checkout_minutes', 30)
        ->assertJsonPath('data.history.0.after.checkout_minutes', 60);
    $this->putJson('/api/v1/admin/billing/settings', ['version' => 0, 'reason' => 'Stale update', 'configuration' => $this->configuration])->assertConflict();
    $this->assertDatabaseCount('billing_configuration_changes', 1);
});

test('discounts and FX change new purchases while existing purchase snapshots stay unchanged', function () {
    $service = app(PaymentService::class);
    $key = (string) Str::uuid();
    $before = $service->quote($this->admin->id, $this->admin->email, 'starter', 'USD', $key, 'paystack');
    $this->configuration['packages'] = [
        ['code' => 'starter', 'name' => 'Bonus bundle', 'credits' => '5500', 'price_ngn' => '4500', 'enabled' => true],
        ['code' => 'hidden', 'name' => 'Unavailable', 'credits' => '1000', 'price_ngn' => '1000', 'enabled' => false],
    ];
    $this->configuration['fx']['ngn_per_usd'] = '1000';
    saveBillingConfiguration($this, $this->configuration);
    expect($service->packagePrices('NGN'))->toHaveCount(1)
        ->and($service->packagePrices('NGN')[0]['amount_minor'])->toBe(450000)
        ->and($service->packagePrices('USD')[0]['amount_minor'])->toBe(450);
    $after = $service->quote($this->admin->id, $this->admin->email, 'starter', 'USD', (string) Str::uuid(), 'flutterwave');
    expect($after['credit_units'])->toBe(550000)->and($after['amount_minor'])->toBe(450)
        ->and($service->quote($this->admin->id, $this->admin->email, 'starter', 'USD', $key, 'paystack')['amount_minor'])->toBe($before['amount_minor']);
    Http::assertNothingSent();
});

test('activity prices apply to new quotes while existing quotes retain model prices and provider costs', function () {
    $quotes = app(UsageQuoteService::class);
    $source = ['audio_url' => 'https://example.test/recording.mp3', 'language_code' => 'en'];
    $old = $quotes->create($this->admin->id, $source, (string) Str::uuid());
    $this->configuration['activities']['transcription'] = ['mode' => 'activity', 'credits' => '35'];
    $this->configuration['quote_minutes'] = 90;
    saveBillingConfiguration($this, $this->configuration);
    $new = $quotes->create($this->admin->id, $source, (string) Str::uuid());
    expect($new['rate']['credit_units'])->toBe(3500)->and($new['rate']['provider_cost_micros'])->toBe(10000)
        ->and($new['rate']['provider_currency'])->toBe('USD')
        ->and(app(BillingRepositoryInterface::class)->quote($old['id'])['rate']['credit_units'])->toBe(2000);
    expect(CarbonImmutable::parse($new['expires_at'])->diffInMinutes(now(), absolute: true))->toBeBetween(89, 91);
});

test('text activity pricing retains the provider cost unit independently', function () {
    config(['billing.rates.translation.google.nmt' => ['unit' => 'character', 'credits' => '0.01', 'provider_cost' => '0.000020', 'provider_currency' => 'USD']]);
    $this->configuration['activities']['translation'] = ['mode' => 'activity', 'credits' => '50'];
    saveBillingConfiguration($this, $this->configuration);
    $rate = app(BillingSettingsInterface::class)->rate('translation', 'google', 'nmt');
    expect($rate['unit_length'])->toBe(1000)->and($rate['credit_units'])->toBe(5000)
        ->and($rate['provider_cost_micros'])->toBe(20)->and($rate['provider_unit_length'])->toBe(1);
});

test('settlement and reporting retain provider costs when customer pricing uses a different text unit', function () {
    config(['billing.rates.translation.google.nmt' => ['unit' => 'character', 'credits' => '0.01', 'provider_cost' => '0.000020', 'provider_currency' => 'USD']]);
    $this->configuration['activities']['translation'] = ['mode' => 'activity', 'credits' => '50'];
    saveBillingConfiguration($this, $this->configuration);
    $translation = Translation::factory()->create(['user_id' => $this->admin->id]);
    $billing = app(BillingRepositoryInterface::class);
    $credits = app(CreditService::class);
    $credits->purchase($this->admin->id, 10000, 'settlement-test');
    $rate = app(BillingSettingsInterface::class)->rate('translation', 'google', 'nmt');
    $quote = $billing->createQuote(['user_id' => $this->admin->id, 'client_key' => (string) Str::uuid(),
        'activity' => 'translation', 'provider' => 'google', 'model' => 'nmt', 'rate' => $rate, 'source' => [],
        'request_source' => [], 'quantity' => 1000, 'credit_units' => 5000, 'status' => 'ready', 'expires_at' => now()->addHour()]);
    $billing->transaction(fn () => $credits->reserve($this->admin->id, $quote, $translation->id));
    $credits->consumeTranslation($translation->id);
    $credits->consumeTranslation($translation->id);
    expect($credits->balance($this->admin->id)['available_units'])->toBe(5000)
        ->and($billing->charge($translation->id, activity: 'translation')['provider_cost_micros'])->toBe(20000);
    $this->withToken($this->token)->getJson('/api/v1/admin/billing/report')->assertOk()
        ->assertJsonPath('data.usage.0.status', 'consumed')->assertJsonPath('data.usage.0.costed_jobs', 1)
        ->assertJsonPath('data.usage.0.provider_currency', 'USD')
        ->assertJsonPath('data.usage.0.estimated_provider_cost_micros', 20000);
});

test('gateways and currencies can be configured independently and credentials remain required', function () {
    $this->configuration['gateways']['paystack']['enabled'] = false;
    saveBillingConfiguration($this, $this->configuration);
    $this->withToken($this->token)->getJson('/api/v1/billing/payment-methods')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'flutterwave');
    $this->configuration['gateways']['paystack']['enabled'] = true;
    $this->configuration['enabled_currencies'] = ['NGN'];
    saveBillingConfiguration($this, $this->configuration, 1);
    $this->getJson('/api/v1/billing/payment-methods')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/billing/payment-methods?currency=USD')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/billing/packages?currency=USD')->assertStatus(422);
    config(['payment.flutterwave.secret' => null]);
    $this->getJson('/api/v1/billing/payment-methods')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'paystack');
});

test('welcome credits are granted once and minimum purchase amounts are enforced', function () {
    $this->configuration['free_credits'] = '25.50';
    $this->configuration['minimum_checkout']['NGN'] = '6000';
    saveBillingConfiguration($this, $this->configuration);
    $user = User::factory()->create();
    $credits = app(CreditService::class);
    expect($credits->balance($user->id)['available_units'])->toBe(2550);
    $this->configuration['free_credits'] = '100';
    saveBillingConfiguration($this, $this->configuration, 1);
    expect($credits->balance($user->id)['available_units'])->toBe(2550);
    $this->assertDatabaseCount('credit_transactions', 1);
    $this->withToken($this->token)->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'package_id' => 'starter', 'currency' => 'NGN', 'payment_method' => 'paystack'])->assertStatus(422);
    $this->assertDatabaseCount('payments', 0);
});

test('stale FX rates prevent new USD purchases while NGN remains available', function () {
    $this->configuration['fx']['updated_at'] = now()->subDays(2)->toIso8601String();
    saveBillingConfiguration($this, $this->configuration);
    $this->withToken($this->token)->getJson('/api/v1/billing/packages?currency=USD')->assertStatus(503);
    $this->getJson('/api/v1/billing/packages?currency=NGN')->assertOk();
});

test('billing report separates payment currencies and credit ledger movements', function () {
    $payments = app(PaymentService::class);
    foreach (['NGN', 'USD'] as $currency) {
        $payment = $payments->quote($this->admin->id, $this->admin->email, 'starter', $currency, (string) Str::uuid(), 'paystack');
        Payment::findOrFail($payment['id'])->update(['status' => 'paid', 'paid_at' => now(), 'provider_fee_minor' => 10]);
    }
    $billing = app(BillingRepositoryInterface::class);
    $billing->transaction(function () use ($billing): void {
        $wallet = $billing->lockWallet($this->admin->id);
        $billing->appendEntry($wallet, 'report-return', 'release', 500);
        $billing->appendEntry($wallet, 'report-purchase', 'purchase', 1000000);
    });
    $this->withToken($this->token)->getJson('/api/v1/admin/billing/report')->assertOk()
        ->assertJsonPath('data.payments.0.currency', 'NGN')->assertJsonPath('data.payments.0.revenue_minor', 500000)
        ->assertJsonPath('data.payments.1.currency', 'USD')->assertJsonPath('data.payments.1.revenue_minor', 334)
        ->assertJsonPath('data.ledger.1.kind', 'release')->assertJsonPath('data.ledger.1.credit_units', 500);
});
