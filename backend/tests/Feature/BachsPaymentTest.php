<?php

use App\Domain\Payment\Services\PaymentService;
use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Payment;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['payment.bachs.enabled' => true, 'payment.bachs.secret' => 'sk_sandbox_test',
        'payment.bachs.webhook_secret' => 'test-signing-secret', 'payment.bachs.callback_url' => 'https://app.example/payment/callback',
        'billing.free_credits' => '0', 'billing.fx.ngn_per_usd' => '1000', 'billing.fx.updated_at' => now()->toIso8601String(),
        'billing.packages.starter' => ['name' => 'Starter', 'credits' => '500', 'price_ngn' => '5000', 'price_usd' => '5.00'],
        'payment.paystack.secret' => null, 'payment.flutterwave.secret' => null]);
    Queue::fake();
    Http::preventStrayRequests();
    $this->seed(PaymentMethodSeeder::class);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

function bachsQuote(object $test): array
{
    return $test->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(),
        'payment_method' => 'bachs', 'package_id' => 'starter', 'currency' => 'USD'])->assertCreated()->json();
}

function bachsResponses(array $quote, array $changes = []): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'sandbox-api.bachs.io/v1/checkout-sessions' => Http::response(['checkout_id' => 'chk_test',
            'checkout_url' => 'https://sandbox-checkout.bachs.io/c/test', 'reference' => $quote['reference'], 'status' => 'open']),
        'sandbox-api.bachs.io/v1/checkout-sessions/chk_test' => Http::response(array_replace_recursive([
            'checkout_id' => 'chk_test', 'reference' => $quote['reference'], 'status' => 'completed',
            'payment_status' => 'succeeded', 'amount' => '5.00', 'currency' => 'USD',
            'charge' => ['payment_id' => 'ch_test', 'status' => 'succeeded', 'amount' => '5.00', 'currency' => 'USD', 'fee_usd' => '0.65'],
        ], $changes)),
    ]);
}

function bachsWebhook(object $test, array $event, ?int $timestamp = null, bool $invalid = false): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp ??= time();
    $signature = $invalid ? str_repeat('0', 64) : hash_hmac('sha256', $timestamp.'.'.$body, 'test-signing-secret');

    return $test->call('POST', '/api/v1/webhooks/bachs', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_BACHS_SIGNATURE_V2' => 't='.$timestamp.',v1=old-signature,v1='.$signature,
    ], $body);
}

test('Bachs is available only for USD with credentials and a webhook secret', function () {
    $this->getJson('/api/v1/billing/payment-methods?currency=USD')->assertOk()
        ->assertJsonPath('data.0.code', 'bachs')->assertJsonPath('data.0.name', 'Bachs');
    $this->getJson('/api/v1/billing/payment-methods?currency=NGN')->assertJsonCount(0, 'data');
    config(['payment.bachs.webhook_secret' => null]);
    $this->getJson('/api/v1/billing/payment-methods?currency=USD')->assertJsonCount(0, 'data');
    Http::assertNothingSent();
});

test('Bachs initializes an idempotent USD checkout and stores its provider identifier', function () {
    $quote = bachsQuote($this);
    bachsResponses($quote);
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertOk()
        ->assertJsonPath('checkout_url', 'https://sandbox-checkout.bachs.io/c/test');
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertOk();
    expect(Payment::findOrFail($quote['id']))->provider_checkout_id->toBe('chk_test')->environment->toBe('test');
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['pricing'] === ['currency' => 'USD', 'amount' => '5.00']
        && $r['payment_method_types'] === ['USD_CARD'] && $r['reference'] === $quote['reference']
        && $r['customer']['email'] === $this->user->email && $r->hasHeader('Idempotency-Key', $quote['reference'])
        && $r['success_url'] === 'https://app.example/payment/callback?reference='.$quote['reference']
        && $r['cancel_url'] === $r['success_url']);
});

test('Bachs signed callbacks verify server state and credit the saved owner exactly once', function () {
    $quote = bachsQuote($this);
    bachsResponses($quote);
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertOk();
    $event = ['id' => 'evt_test', 'type' => 'collection.succeeded', 'data' => ['checkout_id' => 'chk_test']];
    bachsWebhook($this, $event)->assertOk();
    bachsWebhook($this, $event)->assertOk();
    bachsWebhook($this, ['id' => 'evt_other', 'type' => 'checkout.completed', 'data' => ['checkout_id' => 'chk_test']])->assertOk();
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $quote['reference']])->assertOk()->assertJsonPath('status', 'paid');
    expect(CreditTransaction::where('kind', 'purchase')->count())->toBe(1)
        ->and(OutboxEvent::where('event_type', 'PaymentConfirmed')->count())->toBe(1);
    expect(Payment::findOrFail($quote['id']))->provider_transaction_id->toBe('bachs:ch_test')->provider_fee_minor->toBe(65);
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 50000);
});

test('Bachs reconciliation credits a successful checkout without a browser callback or webhook', function () {
    $quote = bachsQuote($this);
    bachsResponses($quote);
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertOk();
    $this->artisan('billing:reconcile')->assertSuccessful();
    expect(Payment::findOrFail($quote['id'])->status)->toBe('paid');
});

test('Bachs rejects invalid or stale webhook signatures before contacting the provider', function (int $offset, bool $invalid) {
    bachsWebhook($this, ['type' => 'collection.succeeded', 'data' => ['checkout_id' => 'chk_test']], time() + $offset, $invalid)->assertForbidden();
    Http::assertNothingSent();
    expect(CreditTransaction::count())->toBe(0);
})->with([[0, true], [-600, false], [600, false]]);

test('Bachs never credits mismatched or incomplete verified payments', function (array $changes, int $status) {
    $quote = bachsQuote($this);
    bachsResponses($quote, $changes);
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertOk();
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $quote['reference']])->assertStatus($status);
    expect(Payment::findOrFail($quote['id'])->status)->not->toBe('paid');
    expect(CreditTransaction::count())->toBe(0);
})->with([
    'wrong reference' => [['reference' => 'other'], 409],
    'wrong checkout' => [['checkout_id' => 'chk_other'], 409],
    'wrong amount' => [['amount' => '6.00', 'charge' => ['amount' => '6.00']], 409],
    'underpaid charge' => [['charge' => ['amount' => '4.00']], 409],
    'wrong currency' => [['currency' => 'NGN', 'charge' => ['currency' => 'NGN']], 409],
    'unpaid charge' => [['charge' => ['status' => 'pending']], 200],
    'unpaid session' => [['payment_status' => 'pending'], 200],
    'missing transaction' => [['charge' => ['payment_id' => null]], 409],
    'empty transaction' => [['charge' => ['payment_id' => '']], 409],
    'invalid transaction' => [['charge' => ['payment_id' => 'chk_not_a_charge']], 409],
]);

test('Bachs live credentials cannot verify a saved sandbox payment', function () {
    $quote = bachsQuote($this);
    bachsResponses($quote);
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertOk();
    config(['payment.bachs.secret' => 'sk_live_test']);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.bachs.io/v1/checkout-sessions/chk_test' => Http::response([
        'checkout_id' => 'chk_test', 'reference' => $quote['reference'], 'status' => 'completed', 'payment_status' => 'succeeded',
        'amount' => '5.00', 'currency' => 'USD', 'charge' => ['payment_id' => 'ch_test', 'status' => 'succeeded', 'amount' => '5.00', 'currency' => 'USD'],
    ])]);
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $quote['reference']])->assertStatus(409);
    expect(CreditTransaction::count())->toBe(0);
});

test('Bachs rejects unsafe or cross-environment checkout links', function (string $url) {
    $quote = bachsQuote($this);
    Http::fake(['sandbox-api.bachs.io/*' => Http::response(['checkout_id' => 'chk_test', 'checkout_url' => $url])]);
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertStatus(503);
    expect(Payment::findOrFail($quote['id'])->provider_checkout_id)->toBeNull();
})->with(['https://attacker.example/pay', 'https://checkout.bachs.io/c/live',
    'http://sandbox-checkout.bachs.io/c/test', 'https://user:pass@sandbox-checkout.bachs.io/c/test']);

test('Bachs verification failures stay pending and can recover on retry', function () {
    $quote = bachsQuote($this);
    bachsResponses($quote);
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertOk();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['sandbox-api.bachs.io/v1/checkout-sessions/chk_test' => Http::response(['error_code' => 'TEMPORARY'], 503)]);
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $quote['reference']])->assertStatus(503);
    expect(Payment::findOrFail($quote['id'])->status)->toBe('pending');
    bachsResponses($quote);
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $quote['reference']])->assertOk()->assertJsonPath('status', 'paid');
});

test('Bachs expires unpaid sessions and preserves owner isolation', function () {
    $quote = bachsQuote($this);
    bachsResponses($quote, ['status' => 'expired', 'payment_status' => 'unpaid', 'charge' => null]);
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertOk();
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $quote['reference']])->assertNotFound();
    app(PaymentService::class)->verify($quote['reference']);
    expect(Payment::findOrFail($quote['id'])->status)->toBe('failed');
    expect(CreditTransaction::count())->toBe(0);
});

test('Bachs also accepts documented pay-prefixed transaction IDs without duplicate credits', function () {
    $quote = bachsQuote($this);
    bachsResponses($quote, ['charge' => ['payment_id' => 'pay_documented']]);
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertOk();
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $this->postJson('/api/v1/billing/payments/verify', ['reference' => $quote['reference']])
            ->assertOk()->assertJsonPath('status', 'paid');
    }
    expect(Payment::findOrFail($quote['id'])->provider_transaction_id)->toBe('bachs:pay_documented')
        ->and(CreditTransaction::where('kind', 'purchase')->count())->toBe(1)
        ->and(OutboxEvent::where('event_type', 'PaymentConfirmed')->count())->toBe(1);
});
