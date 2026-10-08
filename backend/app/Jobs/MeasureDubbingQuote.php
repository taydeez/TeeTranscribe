<?php

namespace App\Jobs;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Services\DubbingService;
use App\Infrastructure\Outbox\OutboxService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MeasureDubbingQuote implements ShouldBeUnique, ShouldQueue
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
        return 'dubbing-quote:'.$this->quoteId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(DubbingService $service, OutboxService $outbox): void
    {
        try {
            $service->measure($this->quoteId);
        } catch (BillingException $exception) {
            if ($exception->httpStatus >= 500) {
                throw $exception;
            } $service->failQuote($this->quoteId);
        }
        $outbox->markPublished($this->outboxEventId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(DubbingService::class)->failQuote($this->quoteId);
        app(OutboxService::class)->markPublished($this->outboxEventId);
    }
}
