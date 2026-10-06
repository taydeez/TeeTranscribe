<?php

namespace App\Jobs;

use App\Domain\Payment\Services\PaymentInvoiceService;
use App\Infrastructure\Outbox\OutboxService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeneratePaymentInvoice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public string $paymentId, public string $outboxEventId)
    {
        $this->onConnection('redis')->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->paymentId;
    }

    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }

    public function handle(PaymentInvoiceService $invoices, OutboxService $outbox): void
    {
        $invoices->generate($this->paymentId);
        $outbox->markPublished($this->outboxEventId);
    }
}
