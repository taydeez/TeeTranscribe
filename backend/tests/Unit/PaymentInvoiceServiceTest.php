<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Payment\Contracts\PaymentInvoiceMailerInterface;
use App\Domain\Payment\Contracts\PaymentInvoiceStorageInterface;
use App\Domain\Payment\Contracts\PaymentRepositoryInterface;
use App\Domain\Payment\Services\PaymentInvoiceService;

test('invoice generation refuses unconfirmed payments without touching storage or mail', function () {
    $repository = Mockery::mock(PaymentRepositoryInterface::class);
    $repository->shouldReceive('exclusive')->with('invoice:payment-id', Mockery::type(Closure::class))->andReturnUsing(fn ($key, $operation) => $operation());
    $repository->shouldReceive('payment')->with('payment-id')->andReturn(['status' => 'pending']);
    $storage = Mockery::mock(PaymentInvoiceStorageInterface::class);
    $mailer = Mockery::mock(PaymentInvoiceMailerInterface::class);
    expect(fn () => (new PaymentInvoiceService($repository, $storage, $mailer))->generate('payment-id'))->toThrow(BillingException::class);
});
test('an already emailed invoice is idempotent', function () {
    $repository = Mockery::mock(PaymentRepositoryInterface::class);
    $repository->shouldReceive('exclusive')->with('invoice:payment-id', Mockery::type(Closure::class))->andReturnUsing(fn ($key, $operation) => $operation());
    $repository->shouldReceive('payment')->with('payment-id')->andReturn(['status' => 'paid', 'invoice_storage_path' => 'invoices/test.pdf', 'invoice_notified_at' => '2026-10-05T20:00:00+00:00']);
    $storage = Mockery::mock(PaymentInvoiceStorageInterface::class);
    $mailer = Mockery::mock(PaymentInvoiceMailerInterface::class);
    expect(fn () => (new PaymentInvoiceService($repository, $storage, $mailer))->generate('payment-id'))->not->toThrow(Throwable::class);
});
