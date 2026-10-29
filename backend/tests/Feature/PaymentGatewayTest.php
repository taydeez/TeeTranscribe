<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Payment\Contracts\PaymentGatewayResolverInterface;
use App\Infrastructure\Payment\Gateways\FlutterwavePaymentGateway;
use App\Infrastructure\Payment\Gateways\PaystackPaymentGateway;
use App\Infrastructure\Persistence\Eloquent\Models\Payment;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'payment.paystack.secret' => 'sk_test_gateway',
        'payment.paystack.callback_url' => 'https://app.example/payments/paystack-return',
        'payment.flutterwave.secret' => 'FLWSECK_TEST-gateway-X',
        'payment.flutterwave.callback_url' => 'https://app.example/payments/flutterwave-return',
        'payment.flutterwave.endpoint' => 'https://api.flutterwave.com/v3',
        'billing.free_credits' => '0',
    ]);
    $this->seed(PaymentMethodSeeder::class);
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

test('resolves both gateway adapters and rejects an unsupported gateway', function () {
    $resolver = app(PaymentGatewayResolverInterface::class);
    expect($resolver->resolve('paystack'))->toBeInstanceOf(PaystackPaymentGateway::class)
        ->and($resolver->resolve('flutterwave'))->toBeInstanceOf(FlutterwavePaymentGateway::class);
    expect(fn () => $resolver->resolve('unsupported'))->toThrow(BillingException::class);
    Http::assertNothingSent();
});

test('keeps an existing payment on paystack while flutterwave is also available', function () {
    $quote = $this->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'payment_method' => 'paystack', 'package_id' => 'starter', 'currency' => 'NGN'])->assertCreated()->json();
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/existing']])]);
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertOk()->assertJsonPath('checkout_url', 'https://checkout.paystack.com/existing');
    $this->assertDatabaseHas('payments', ['id' => $quote['id'], 'gateway' => 'paystack']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['callback_url'] === 'https://app.example/payments/paystack-return');
});

test('initializes flutterwave with major currency units and verifies credits exactly once', function () {
    $quote = $this->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'payment_method' => 'flutterwave', 'package_id' => 'starter', 'currency' => 'NGN'])->assertCreated()->json();
    Http::fake([
        'api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/test']]),
        'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(['status' => 'success', 'data' => [
            'id' => 123, 'tx_ref' => $quote['reference'], 'status' => 'successful', 'currency' => 'NGN',
            'amount' => 5000, 'app_fee' => 75.50, 'customer' => ['email' => $this->user->email],
        ]]),
    ]);
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertOk();
    Http::assertSent(fn ($request) => $request->url() === 'https://api.flutterwave.com/v3/payments' && $request['amount'] === '5000.00' && $request['tx_ref'] === $quote['reference'] && $request['customer']['email'] === $this->user->email && $request['redirect_url'] === 'https://app.example/payments/flutterwave-return');
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $quote['reference']])->assertOk()->assertJsonPath('status', 'paid');
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $quote['reference']])->assertOk();
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 500000);
    expect(Payment::findOrFail($quote['id'])->provider_transaction_id)->toBe('flutterwave:123');
    expect(Payment::findOrFail($quote['id'])->provider_fee_minor)->toBe(7550);
    Http::assertSentCount(2);
});

test('rejects flutterwave payment details that differ from the purchased quote', function () {
    $quote = $this->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'payment_method' => 'flutterwave', 'package_id' => 'starter', 'currency' => 'NGN'])->assertCreated()->json();
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => [
        'id' => 321, 'tx_ref' => $quote['reference'], 'status' => 'successful', 'currency' => 'USD',
        'amount' => 5000, 'customer' => ['email' => $this->user->email],
    ]])]);
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $quote['reference']])->assertStatus(409);
    $this->assertDatabaseCount('credit_transactions', 0);
});

test('credits the purchase owner when the flutterwave payer email differs', function () {
    $quote = $this->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'payment_method' => 'flutterwave', 'package_id' => 'starter', 'currency' => 'NGN'])->assertCreated()->json();
    Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(['status' => 'success', 'data' => [
        'id' => 654, 'tx_ref' => $quote['reference'], 'status' => 'successful', 'currency' => 'NGN',
        'amount' => 5000, 'customer' => ['email' => 'different-payer@example.com'],
    ]])]);

    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $quote['reference']])->assertOk()->assertJsonPath('status', 'paid');
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $quote['reference']])->assertOk();
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 500000);
    $this->assertDatabaseHas('payments', ['id' => $quote['id'], 'user_id' => $this->user->id, 'status' => 'paid']);
    $this->assertDatabaseCount('credit_transactions', 1);
    Http::assertSentCount(1);
});

test('accepts Flutterwaves development checkout only for a test payment', function (string $environment, string $url, int $status) {
    config(['payment.flutterwave.secret' => $environment === 'test' ? 'FLWSECK_TEST-gateway-X' : 'FLWSECK_LIVE-gateway-X']);
    $quote = $this->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'payment_method' => 'flutterwave', 'package_id' => 'starter', 'currency' => 'NGN'])->assertCreated()->json();
    Http::fake(['api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => $url]])]);
    $response = $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('checkout_url', $url);
    }
})->with([
    'test sandbox' => ['test', 'https://checkout-v2.dev-flutterwave.com/v3/hosted/pay/test', 200],
    'live production' => ['live', 'https://checkout.flutterwave.com/v3/hosted/pay/live', 200],
    'live rejects sandbox' => ['live', 'https://checkout-v2.dev-flutterwave.com/v3/hosted/pay/test', 503],
    'test rejects lookalike' => ['test', 'https://checkout-v2.dev-flutterwave.com.attacker.example/pay', 503],
    'test rejects HTTP' => ['test', 'http://checkout-v2.dev-flutterwave.com/pay', 503],
    'test rejects credentials' => ['test', 'https://user:pass@checkout-v2.dev-flutterwave.com/pay', 503],
    'test rejects custom port' => ['test', 'https://checkout-v2.dev-flutterwave.com:8443/pay', 503],
]);

test('rejects checkout links outside the chosen providers hosted domain', function (string $provider, array $data) {
    $quote = $this->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'payment_method' => $provider, 'package_id' => 'starter', 'currency' => 'NGN'])->assertCreated()->json();
    Http::fake(['*' => Http::response(['status' => $provider === 'paystack' ? true : 'success', 'data' => $data])]);
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertStatus(503);
})->with([
    'paystack' => ['paystack', ['authorization_url' => 'https://attacker.example/checkout']],
    'flutterwave' => ['flutterwave', ['link' => 'https://attacker.example/checkout']],
]);
