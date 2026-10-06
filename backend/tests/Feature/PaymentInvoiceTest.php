<?php

use App\Domain\Payment\Contracts\PaymentInvoiceMailerInterface;
use App\Domain\Payment\Contracts\PaymentInvoiceStorageInterface;
use App\Domain\Payment\Services\PaymentInvoiceService;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Payment;
use App\Jobs\GeneratePaymentInvoice;
use App\Mail\PaymentInvoiceMail;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
beforeEach(function () {
    config(['payment.paystack.secret' => 'sk_test_invoice', 'payment.paystack.enabled' => true, 'billing.free_credits' => '0']);
    $this->seed(PaymentMethodSeeder::class);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    Http::preventStrayRequests();
    Mail::fake();
    Bus::fake();
    Storage::fake('r2');
});
function confirmedInvoicePayment($test): Payment
{
    $quote = $test->postJson('/api/v1/billing/purchases', ['client_key' => (string) Str::uuid(), 'package_id' => 'starter', 'currency' => 'NGN', 'payment_method' => 'paystack'])->assertCreated()->json();
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['id' => 987, 'reference' => $quote['reference'], 'status' => 'success', 'amount' => 500000, 'currency' => 'NGN', 'domain' => 'test', 'customer' => ['email' => $test->user->email]]])]);
    $test->postJson('/api/v1/billing/payments/verify', ['reference' => $quote['reference']])->assertOk()->assertJsonPath('status', 'paid');

    return Payment::findOrFail($quote['id']);
}
test('confirmation writes one invoice event and the publisher dispatches a retryable job', function () {
    $payment = confirmedInvoicePayment($this);
    $this->postJson('/api/v1/billing/payments/verify', ['reference' => $payment->reference])->assertOk();
    $event = OutboxEvent::where('event_type', 'PaymentConfirmed')->sole();
    expect($event->aggregate_id)->toBe($payment->id)->and($event->published_at)->toBeNull();
    $this->artisan('outbox:publish')->assertSuccessful();
    Bus::assertDispatched(GeneratePaymentInvoice::class, fn ($job) => $job->paymentId === $payment->id && $job->outboxEventId === $event->id && $job->queue === 'default');
    Mail::assertNothingSent();
});

test('the publisher recovers invoices for payments confirmed before the invoice feature', function () {
    $payment = confirmedInvoicePayment($this);
    OutboxEvent::where('event_type', 'PaymentConfirmed')->delete();
    $this->artisan('outbox:publish')->assertSuccessful();
    expect(OutboxEvent::where('event_type', 'PaymentConfirmed')->sole()->aggregate_id)->toBe($payment->id);
    Bus::assertDispatched(GeneratePaymentInvoice::class, fn ($job) => $job->paymentId === $payment->id);
});
test('generates a PDF on R2 and sends the invoice once across duplicate jobs', function () {
    $payment = confirmedInvoicePayment($this);
    $event = OutboxEvent::where('event_type', 'PaymentConfirmed')->sole();
    $job = new GeneratePaymentInvoice($payment->id, $event->id);
    $job->handle(app(PaymentInvoiceService::class), app(OutboxService::class));
    $payment->refresh();
    Storage::disk('r2')->assertExists($payment->invoice_storage_path);
    $pdf = Storage::disk('r2')->get($payment->invoice_storage_path);
    expect($pdf)->toStartWith('%PDF-')->and($payment->invoice_number)->toBe('INV-'.strtoupper($payment->id))->and($payment->invoice_notified_at)->not->toBeNull()->and($event->refresh()->published_at)->not->toBeNull();
    Mail::assertSent(PaymentInvoiceMail::class, function ($mail) use ($payment, $pdf): bool {
        $mail->assertHasAttachedData($pdf, $payment->invoice_number.'.pdf', ['mime' => 'application/pdf']);

        return $mail->hasTo($payment->customer_email) && str_contains($mail->render(), 'Payment confirmed');
    });
    $job->handle(app(PaymentInvoiceService::class), app(OutboxService::class));
    Mail::assertSentCount(1);
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 500000);
});
test('failed uploads leave the invoice pending without emailing or publishing the event', function () {
    $payment = confirmedInvoicePayment($this);
    $event = OutboxEvent::where('event_type', 'PaymentConfirmed')->sole();
    $storage = Mockery::mock(PaymentInvoiceStorageInterface::class);
    $storage->shouldReceive('create')->once()->andThrow(new RuntimeException('R2 unavailable'));
    $this->app->instance(PaymentInvoiceStorageInterface::class, $storage);
    $job = new GeneratePaymentInvoice($payment->id, $event->id);
    expect(fn () => $job->handle(app(PaymentInvoiceService::class), app(OutboxService::class)))->toThrow(RuntimeException::class);
    expect($payment->refresh()->invoice_storage_path)->toBeNull()->and($payment->invoice_notified_at)->toBeNull()->and($event->refresh()->published_at)->toBeNull();
    Mail::assertNothingSent();
});
test('only the payment owner can obtain a short lived invoice download', function () {
    $payment = confirmedInvoicePayment($this);
    $this->getJson('/api/v1/billing/payments/'.$payment->id.'/invoice')->assertStatus(409);
    app(PaymentInvoiceService::class)->generate($payment->id);
    $payment->refresh();
    $signed = 0;
    Storage::disk('r2')->buildTemporaryUrlsUsing(function ($path, $expiry, $options) use ($payment, &$signed): string {
        $signed++;
        expect($path)->toBe($payment->invoice_storage_path)->and($options['ResponseContentDisposition'])->toBe('attachment; filename="'.$payment->invoice_number.'.pdf"');

        return 'https://r2.example/invoice.pdf?signature=test';
    });
    $this->getJson('/api/v1/billing/payments/'.$payment->id.'/invoice')->assertOk()->assertJsonPath('url', 'https://r2.example/invoice.pdf?signature=test')->assertJsonStructure(['expires_at'])->assertHeader('Cache-Control', 'no-store, private');
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/billing/payments/'.$payment->id.'/invoice')->assertNotFound();
    expect($signed)->toBe(1);
});
test('payment history exposes invoice readiness without leaking its storage path', function () {
    $payment = confirmedInvoicePayment($this);
    $this->getJson('/api/v1/billing/history/payments')->assertJsonPath('data.0.invoice_ready', false);
    app(PaymentInvoiceService::class)->generate($payment->id);
    $data = $this->getJson('/api/v1/billing/history/payments')->assertOk()->assertJsonPath('data.0.invoice_ready', true)->json('data.0');
    expect($data)->not->toHaveKey('invoice_storage_path')->and($data['invoice_number'])->toBe('INV-'.strtoupper($payment->id));
});
test('failed mail can retry without reuploading the saved invoice or adding credits again', function () {
    $payment = confirmedInvoicePayment($this);
    $mailer = Mockery::mock(PaymentInvoiceMailerInterface::class);
    $mailer->shouldReceive('send')->once()->ordered()->andThrow(new RuntimeException('Mail unavailable'));
    $mailer->shouldReceive('send')->once()->ordered()->andReturnNull();
    $this->app->instance(PaymentInvoiceMailerInterface::class, $mailer);
    $service = app(PaymentInvoiceService::class);
    expect(fn () => $service->generate($payment->id))->toThrow(RuntimeException::class);
    $payment->refresh();
    $pdf = Storage::disk('r2')->get($payment->invoice_storage_path);
    expect($payment->invoice_notified_at)->toBeNull();
    $service->generate($payment->id);
    $service->generate($payment->id);
    expect(Storage::disk('r2')->get($payment->invoice_storage_path))->toBe($pdf)->and($payment->refresh()->invoice_notified_at)->not->toBeNull();
    $this->assertDatabaseCount('credit_transactions', 1);
});
