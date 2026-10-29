<?php

use App\Infrastructure\Persistence\Eloquent\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
beforeEach(function () {
    config(['payment.paystack.secret' => 'sk_test_methods', 'payment.paystack.enabled' => true, 'payment.paystack.usd_enabled' => true, 'payment.flutterwave.secret' => 'FLWSECK_TEST-methods-X', 'payment.flutterwave.enabled' => true, 'payment.flutterwave.usd_enabled' => false, 'payment.flutterwave.secret_hash' => 'webhook-test-hash', 'billing.free_credits' => '0']);
    $this->seed(PaymentMethodSeeder::class);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    Http::preventStrayRequests();
});
test('lists both active methods and allows purchases through either gateway', function () {
    $this->getJson('/api/v1/billing/payment-methods')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.code', 'paystack')->assertJsonPath('data.1.code', 'flutterwave');
    foreach (['paystack', 'flutterwave'] as $method) {
        $this->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'package_id' => 'starter', 'currency' => 'NGN', 'payment_method' => $method])->assertCreated()->assertJsonPath('gateway', $method);
    }
    $this->assertDatabaseCount('payments', 2);
    Http::assertNothingSent();
});
test('filters methods by activation credentials and currency', function () {
    $this->getJson('/api/v1/billing/payment-methods?currency=USD')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'paystack');
    PaymentMethod::where('code', 'paystack')->update(['is_active' => false]);
    $this->getJson('/api/v1/billing/payment-methods')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'flutterwave');
    config(['payment.flutterwave.secret' => null]);
    $this->getJson('/api/v1/billing/payment-methods')->assertJsonCount(0, 'data');
});
test('purchase retries preserve the method and cannot switch providers', function () {
    $input = ['client_key' => (string) Str::uuid(), 'package_id' => 'starter', 'currency' => 'NGN', 'payment_method' => 'paystack'];
    $id = $this->postJson('/api/v1/billing/purchases', $input)->assertCreated()->json('id');
    $this->postJson('/api/v1/billing/purchases', $input)->assertCreated()->assertJsonPath('id', $id);
    $this->postJson('/api/v1/billing/purchases', [...$input, 'payment_method' => 'flutterwave'])->assertStatus(409);
    $this->assertDatabaseCount('payments', 1);
});
test('seeding again preserves activation and does not create payments', function () {
    PaymentMethod::where('code', 'paystack')->update(['is_active' => false]);
    $this->seed(PaymentMethodSeeder::class);
    $this->assertDatabaseCount('payment_methods', 2);
    $this->assertDatabaseCount('payments', 0);
    expect(PaymentMethod::where('code', 'paystack')->first()->is_active)->toBeFalse();
});
test('flutterwave callbacks verify server side and credit only once', function () {
    $quote = $this->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'package_id' => 'starter', 'currency' => 'NGN', 'payment_method' => 'flutterwave'])->assertCreated()->json();
    Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(['status' => 'success', 'data' => ['id' => 999, 'tx_ref' => $quote['reference'], 'status' => 'successful', 'currency' => 'NGN', 'amount' => 5000, 'customer' => ['email' => $this->user->email]]])]);
    $payload = ['event' => 'charge.completed', 'data' => ['tx_ref' => $quote['reference'], 'amount' => 1]];
    $this->postJson('/api/v1/webhooks/flutterwave', $payload, ['verif-hash' => 'wrong'])->assertForbidden();
    Http::assertNothingSent();
    $this->postJson('/api/v1/webhooks/flutterwave', $payload, ['verif-hash' => 'webhook-test-hash'])->assertOk();
    $this->postJson('/api/v1/webhooks/flutterwave', $payload, ['verif-hash' => 'webhook-test-hash'])->assertOk();
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 500000);
    $this->assertDatabaseCount('credit_transactions', 1);
    Http::assertSentCount(1);
});
test('rejects callbacks from a different provider', function () {
    $quote = $this->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'package_id' => 'starter', 'currency' => 'NGN', 'payment_method' => 'paystack'])->assertCreated()->json();
    $this->postJson('/api/v1/webhooks/flutterwave', ['event' => 'charge.completed', 'data' => ['tx_ref' => $quote['reference']]], ['verif-hash' => 'webhook-test-hash'])->assertStatus(409);
    Http::assertNothingSent();
    $this->assertDatabaseCount('credit_transactions', 0);
});
