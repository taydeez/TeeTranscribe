<?php

namespace App\Jobs;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\UsageQuoteService;
use App\Infrastructure\Outbox\OutboxService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class MeasureBillingQuote implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public string $quoteId, public string $outboxEventId)
    {
        $this->onConnection('redis')->onQueue('ingestion');
    }

    public function uniqueId(): string
    {
        return $this->quoteId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(UsageQuoteService $quotes, OutboxService $outbox): void
    {
        try {
            $quotes->measure($this->quoteId);
        } catch (BillingException $exception) {
            if ($exception->httpStatus >= 500) {
                throw $exception;
            }
            $quotes->fail($this->quoteId);
            Log::warning('Billing media inspection failed', ['quote_id' => $this->quoteId, 'reason' => $exception->getMessage()]);
        }
        $outbox->markPublished($this->outboxEventId);
    }

    public function failed(?Throwable $exception): void
    {
        app(UsageQuoteService::class)->fail($this->quoteId);
        app(OutboxService::class)->markPublished($this->outboxEventId);
        Log::warning('Billing media inspection exhausted retries', ['quote_id' => $this->quoteId]);
    }
}
