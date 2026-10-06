<?php

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Contracts\MediaDurationInspectorInterface;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Billing\Services\UsageQuoteService;
use App\Domain\Transcriber\Events\TranscriptionFailed;
use App\Infrastructure\AI\Transcriber\Intron\IntronClient;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\BillingQuote;
use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Payment;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Jobs\MeasureBillingQuote;
use App\Jobs\PollIntronTranscription;
use App\Jobs\SubmitTranscription;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'payment.paystack.secret' => 'sk_test_billing',
        'payment.paystack.usd_enabled' => true,
        'billing.fx.ngn_per_usd' => '1500',
        'billing.fx.updated_at' => now()->toIso8601String(),
        'billing.free_credits' => '0',
        'billing.rates.transcription.deepgram.nova-2.credits' => '20',
        'billing.rates.transcription.deepgram.nova-2.provider_cost' => '0.01',
        'billing.rates.transcription.intron.default.credits' => '30',
        'billing.rates.transcription.intron.default.provider_cost' => '0.01',
        'transcriber.deepgram.model' => 'nova-2',
        'transcriber.fallback' => 'deepgram',
        'transcriber.intron.languages' => ['yo', 'ig', 'ha', 'pcm', 'en-NG'],
    ]);
    $this->seed(PaymentMethodSeeder::class);
    Http::preventStrayRequests();
    Bus::fake();
    Event::fake([TranscriptionFailed::class]);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->mock(MediaDurationInspectorInterface::class)->shouldReceive('measure')->andReturn([
        'duration_ms' => 90001, 'audio_url' => 'https://storage.example.com/verified.mp3',
        'audio_storage_path' => 'billing-media/verified', 'file_name' => 'interview.mp3',
    ]);
});

function readyBillingQuote($test, string $language = 'en'): array
{
    $quote = $test->postJson('/api/v1/billing/quotes', [
        'client_key' => (string) Str::uuid(), 'audio_url' => 'https://example.com/interview.mp3',
        'language_code' => $language, 'duration' => 1,
    ])->assertAccepted()->json();
    app(UsageQuoteService::class)->measure($quote['id']);

    return $test->getJson('/api/v1/billing/quotes/'.$quote['id'])->assertOk()->json();
}

function successfulBillingPayment(Payment $payment, array $overrides = []): array
{
    return array_replace([
        'id' => 1234, 'reference' => $payment->reference, 'status' => 'success',
        'amount' => $payment->amount_minor, 'currency' => $payment->currency, 'domain' => 'test',
        'customer' => ['email' => $payment->customer_email], 'fees' => 250,
    ], $overrides);
}

test('requires authentication for billing and processing', function () {
    auth()->forgetGuards();
    $this->app['auth']->guard('sanctum')->forgetUser();
    $this->getJson('/api/v1/billing/balance')->assertUnauthorized();
    $this->postJson('/api/v1/transcribe', [])->assertUnauthorized();
});

test('provides NGN packages independently of FX and rejects stale USD quotes', function () {
    config(['billing.fx.updated_at' => now()->subDays(2)->toIso8601String()]);
    $this->getJson('/api/v1/billing/packages?currency=NGN')->assertOk()->assertJsonPath('data.0.amount_minor', 500000);
    $this->getJson('/api/v1/billing/packages?currency=USD')->assertStatus(503);
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 0);
});

test('locks the USD price and initializes checkout once with server amounts', function () {
    $input = ['client_key' => (string) Str::uuid(), 'payment_method' => 'paystack', 'package_id' => 'starter', 'currency' => 'USD'];
    $quote = $this->postJson('/api/v1/billing/purchases', $input)->assertCreated()->assertJsonPath('amount_minor', 334)->assertJsonPath('credit_units', 500000)->json();
    config(['billing.fx.ngn_per_usd' => '2000']);
    $this->postJson('/api/v1/billing/purchases', $input)->assertCreated()->assertJsonPath('id', $quote['id'])->assertJsonPath('amount_minor', 334);
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/test-code']])]);
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertOk();
    $this->postJson('/api/v1/billing/purchases/'.$quote['id'].'/checkout')->assertOk();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['amount'] === 334 && $request['currency'] === 'USD' && $request['email'] === $this->user->email);
    $this->assertDatabaseCount('credit_transactions', 0);
});

test('only credits a verified payment once across redirect and duplicate webhooks', function () {
    $quote = $this->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'payment_method' => 'paystack', 'package_id' => 'starter', 'currency' => 'NGN'])->assertCreated()->json();
    $payment = Payment::findOrFail($quote['id']);
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => successfulBillingPayment($payment)])]);
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $payment->reference])->assertOk()->assertJsonPath('status', 'paid');
    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $payment->reference]]);
    $signature = hash_hmac('sha512', $body, 'sk_test_billing');
    for ($i = 0; $i < 2; $i++) {
        $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature], $body)->assertOk();
    }
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 500000);
    expect(CreditTransaction::where('kind', 'purchase')->count())->toBe(1);
    Http::assertSentCount(1);
});

test('rejects forged webhook signatures before contacting paystack', function () {
    $this->postJson('/api/v1/webhooks/paystack', ['event' => 'charge.success', 'data' => ['reference' => 'fake']], ['x-paystack-signature' => 'forged'])->assertForbidden();
    Http::assertNothingSent();
    $this->assertDatabaseCount('credit_transactions', 0);
});

test('does not credit a mismatched verified payment', function (array $override) {
    $quote = $this->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'payment_method' => 'paystack', 'package_id' => 'starter', 'currency' => 'NGN'])->assertCreated()->json();
    $payment = Payment::findOrFail($quote['id']);
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => successfulBillingPayment($payment, $override)])]);
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $payment->reference])->assertStatus(409);
    $this->assertDatabaseCount('credit_transactions', 0);
})->with([
    'wrong amount' => [['amount' => 1]], 'wrong currency' => [['currency' => 'USD']],
    'wrong reference' => [['reference' => 'another-purchase']], 'wrong environment' => [['domain' => 'live']],
]);

test('measures media server-side and snapshots the selected provider price', function () {
    $quote = readyBillingQuote($this);
    expect($quote['quantity'])->toBe(90001)->and($quote['credit_units'])->toBe(3001);
    config(['billing.rates.transcription.deepgram.nova-2.credits' => '100']);
    app(CreditService::class)->purchase($this->user->id, 10000, 'seed');
    $response = $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertAccepted()->json();
    $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertAccepted()->assertJsonPath('id', $response['id']);
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 6999)->assertJsonPath('reserved_units', 3001);
    $this->assertDatabaseCount('transcriptions', 1);
    $this->assertDatabaseCount('usage_charges', 1);
    $this->assertDatabaseHas('outbox_events', ['event_type' => 'TranscriptionSubmitted', 'aggregate_id' => $response['id']]);
    $this->assertDatabaseHas('folders', ['user_id' => $this->user->id, 'name' => now()->format('F j, Y')]);
    expect(Transcription::findOrFail($response['id'])->duration)->toBe(90.001);
    Bus::assertNotDispatched(SubmitTranscription::class);
});

test('insufficient credits roll back the entire submission', function () {
    $quote = readyBillingQuote($this);
    $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertStatus(402);
    $this->assertDatabaseCount('transcriptions', 0);
    $this->assertDatabaseCount('usage_charges', 0);
    $this->assertDatabaseCount('folders', 0);
    expect(BillingQuote::findOrFail($quote['id'])->status)->toBe('ready');
});

test('does not disclose another users quotes or payments', function () {
    $quote = readyBillingQuote($this);
    $payment = $this->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'payment_method' => 'paystack', 'package_id' => 'starter', 'currency' => 'NGN'])->assertCreated()->json();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/billing/quotes/'.$quote['id'])->assertNotFound();
    $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertNotFound();
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $payment['reference']])->assertNotFound();
    Http::assertNothingSent();
});

test('expired quotes cannot reserve credits', function () {
    $quote = readyBillingQuote($this);
    BillingQuote::findOrFail($quote['id'])->update(['expires_at' => now()->subMinute()]);
    app(CreditService::class)->purchase($this->user->id, 10000, 'seed');
    $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertStatus(409);
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('reserved_units', 0);
});

test('deepgram consumes once and export failure does not refund a usable transcript', function () {
    $quote = readyBillingQuote($this);
    app(CreditService::class)->purchase($this->user->id, 10000, 'seed');
    $id = $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertAccepted()->json('id');
    Transcription::findOrFail($id)->update(['provider_request_id' => 'verified-request']);
    $url = URL::signedRoute('deepgram.callback', ['transcription' => $id], absolute: false);
    $payload = ['metadata' => ['request_id' => 'verified-request', 'duration' => 90.001], 'results' => ['channels' => [['alternatives' => [['transcript' => 'Usable transcript']]]]]];
    $this->postJson($url, $payload)->assertNoContent();
    $this->postJson($url, $payload)->assertNoContent();
    app(TranscriptionOutcomePublisher::class)->failed($id);
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 6999)->assertJsonPath('reserved_units', 0);
    expect(UsageCharge::where('transcription_id', $id)->sole()->status)->toBe('consumed');
    expect(CreditTransaction::where('kind', 'consume')->count())->toBe(1);
    expect(UsageCharge::where('transcription_id', $id)->sole()->provider_cost_micros)->toBe(15001);
});

test('terminal provider failure releases the reservation once', function () {
    $quote = readyBillingQuote($this, 'yo');
    app(CreditService::class)->purchase($this->user->id, 10000, 'seed');
    $id = $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertAccepted()->json('id');
    app(TranscriptionOutcomePublisher::class)->failed($id, pendingOnly: true);
    app(TranscriptionOutcomePublisher::class)->failed($id, pendingOnly: true);
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 10000)->assertJsonPath('reserved_units', 0);
    expect(UsageCharge::where('transcription_id', $id)->sole()->status)->toBe('released');
    expect(CreditTransaction::where('kind', 'release')->count())->toBe(1);
});

test('measuring jobs complete their outbox event and history is scoped and paginated', function () {
    $quote = $this->postJson('/api/v1/billing/quotes', ['client_key' => (string) Str::uuid(), 'audio_url' => 'https://example.com/file.mp3', 'language_code' => 'en'])->assertAccepted()->json();
    $event = OutboxEvent::where('aggregate_id', $quote['id'])->sole();
    (new MeasureBillingQuote($quote['id'], $event->id))->handle(app(UsageQuoteService::class), app(OutboxService::class));
    expect($event->refresh()->published_at)->not->toBeNull();
    app(CreditService::class)->purchase($this->user->id, 100, 'one');
    app(CreditService::class)->purchase($this->user->id, 100, 'two');
    app(CreditService::class)->purchase(User::factory()->create()->id, 100, 'other');
    $this->getJson('/api/v1/billing/history/ledger?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
});

test('intron completion consumes the quoted credits without charging duplicate polls', function () {
    $quote = readyBillingQuote($this, 'yo');
    app(CreditService::class)->purchase($this->user->id, 10000, 'seed');
    $id = $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertAccepted()->json('id');
    Transcription::findOrFail($id)->update(['provider_request_id' => 'file-123']);
    Http::fake(['*' => Http::response(['data' => ['processing_status' => 'FILE_TRANSCRIBED', 'audio_transcript' => 'Mo n soro Yoruba.', 'processed_audio_duration_in_seconds' => 90.001]])]);
    $job = new PollIntronTranscription($id);
    $job->handle(app(IntronClient::class), app(OutboxService::class));
    $job->handle(app(IntronClient::class), app(OutboxService::class));
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 5499)->assertJsonPath('reserved_units', 0);
    expect(CreditTransaction::where('kind', 'consume')->count())->toBe(1);
    Http::assertSentCount(1);
});

test('an empty provider transcript releases credits without generating empty exports', function () {
    $quote = readyBillingQuote($this);
    app(CreditService::class)->purchase($this->user->id, 10000, 'seed');
    $id = $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertAccepted()->json('id');
    Transcription::findOrFail($id)->update(['provider_request_id' => 'empty-request']);
    $url = URL::signedRoute('deepgram.callback', ['transcription' => $id], absolute: false);
    $this->postJson($url, ['metadata' => ['request_id' => 'empty-request'], 'results' => ['channels' => [['alternatives' => [['transcript' => '']]]]]])->assertNoContent();
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 10000)->assertJsonPath('reserved_units', 0);
    $this->assertDatabaseMissing('outbox_events', ['event_type' => 'TranscriptionCompleted', 'aggregate_id' => $id]);
});

test('a failed credit ledger write rolls back payment confirmation and can be retried', function () {
    $quote = $this->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'payment_method' => 'paystack', 'package_id' => 'starter', 'currency' => 'NGN'])->assertCreated()->json();
    $payment = Payment::findOrFail($quote['id']);
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => successfulBillingPayment($payment)])]);
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 0);
    $simulateFailure = true;
    CreditTransaction::creating(function () use (&$simulateFailure): void {
        if ($simulateFailure) {
            throw new RuntimeException('Simulated database failure');
        }
    });
    try {
        $this->postJson('/api/v1/billing/payments/verify', ['reference' => $payment->reference])->assertStatus(500);
        expect($payment->refresh()->status)->toBe('quoted');
        $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 0);
    } finally {
        $simulateFailure = false;
        CreditTransaction::flushEventListeners();
    }
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $payment->reference])->assertOk()->assertJsonPath('status', 'paid');
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 500000);
});

test('quote inspection starts immediately after commit and retains its durable outbox event', function () {
    $quote = $this->postJson('/api/v1/billing/quotes', ['client_key' => (string) Str::uuid(), 'audio_url' => 'https://example.com/audio.mp3', 'language_code' => 'en'])->assertAccepted()->json();
    Bus::assertDispatchedTimes(MeasureBillingQuote::class, 1);
    $this->artisan('outbox:publish')->assertSuccessful();
    Bus::assertDispatched(MeasureBillingQuote::class, fn ($job) => $job->quoteId === $quote['id']);
    $this->assertDatabaseHas('outbox_events', ['aggregate_id' => $quote['id'], 'attempts' => 1, 'published_at' => null]);
    Bus::assertDispatchedTimes(MeasureBillingQuote::class, 1);
    OutboxEvent::where('aggregate_id', $quote['id'])->update(['updated_at' => now()->subMinutes(6)]);
    $job = Bus::dispatched(MeasureBillingQuote::class)->first();
    (new UniqueLock(app(Repository::class)))->release($job);
    $this->artisan('outbox:publish')->assertSuccessful();
    Bus::assertDispatchedTimes(MeasureBillingQuote::class, 2);
});

test('rolling back a quote event does not start media inspection', function () {
    $id = (string) Str::ulid();
    expect(fn () => DB::transaction(function () use ($id): void {
        app(BillingRepositoryInterface::class)->enqueue('billing:quote:'.$id, 'BillingQuoteRequested', $id, []);
        throw new RuntimeException('Roll back quote creation.');
    }))->toThrow(RuntimeException::class);
    $this->assertDatabaseMissing('outbox_events', ['aggregate_id' => $id]);
    Bus::assertNothingDispatched();
});

test('a queue outage preserves the quote and releases its dispatch lock for outbox recovery', function () {
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable.'));
    $quote = $this->postJson('/api/v1/billing/quotes', ['client_key' => (string) Str::uuid(), 'audio_url' => 'https://example.com/audio.mp3', 'language_code' => 'en'])->assertAccepted()->json();
    $event = OutboxEvent::where('aggregate_id', $quote['id'])->sole();
    expect($event->published_at)->toBeNull()->and($event->attempts)->toBe(1);
    $this->assertDatabaseHas('billing_quotes', ['id' => $quote['id'], 'status' => 'measuring']);
    $job = new MeasureBillingQuote($quote['id'], $event->id);
    $lock = Cache::lock(UniqueLock::getKey($job));
    expect($lock->get())->toBeTrue();
    $lock->release();
});

test('payment reconciliation credits a confirmed purchase when a webhook was missed', function () {
    $quote = $this->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'payment_method' => 'paystack', 'package_id' => 'starter', 'currency' => 'NGN'])->assertCreated()->json();
    $payment = Payment::findOrFail($quote['id']);
    $payment->update(['status' => 'pending']);
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => successfulBillingPayment($payment)])]);
    $this->artisan('billing:reconcile')->assertSuccessful();
    $this->artisan('billing:reconcile')->assertSuccessful();
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 500000);
    Http::assertSentCount(1);
});

test('recovers a reserved balance when the providers callback window has elapsed', function () {
    $quote = readyBillingQuote($this);
    app(CreditService::class)->purchase($this->user->id, 10000, 'seed');
    $id = $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertAccepted()->json('id');
    UsageCharge::where('transcription_id', $id)->update(['created_at' => now()->subHours(27)]);
    $this->artisan('billing:reconcile')->assertSuccessful();
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 10000)->assertJsonPath('reserved_units', 0);
    expect(Transcription::findOrFail($id)->status)->toBe('failed');
});
