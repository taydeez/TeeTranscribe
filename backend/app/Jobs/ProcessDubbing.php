<?php

namespace App\Jobs;

use App\Domain\Dubbing\Contracts\DubbingRepositoryInterface;
use App\Domain\Dubbing\Services\ProcessDubbing as Processor;
use App\Infrastructure\Outbox\OutboxService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessDubbing implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 5400;

    public int $uniqueFor = 6000;

    public function __construct(public string $dubbingId, public string $outboxEventId)
    {
        $this->onConnection('dubbing')->onQueue('dubbing');
    }

    public function uniqueId(): string
    {
        return 'dubbing:'.$this->dubbingId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(Processor $processor, OutboxService $outbox): void
    {
        if ($processor->handle($this->dubbingId)) {
            $outbox->markPublished($this->outboxEventId);
            $record = app(DubbingRepositoryInterface::class)->find($this->dubbingId);
            if ($record?->status === 'failed') {
                Log::warning('Dubbing finished with a failed outcome.', [
                    'dubbing_id' => $this->dubbingId,
                    'provider_project_id' => $record->projectId,
                    'provider_language_id' => $record->languageId,
                    'provider_completed_at' => $record->providerCompletedAt,
                    'failure_reason' => $record->failureReason,
                ]);
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        app(Processor::class)->fail($this->dubbingId);
        app(OutboxService::class)->markPublished($this->outboxEventId);
        Log::warning('Dubbing processing exhausted retries.', ['dubbing_id' => $this->dubbingId, 'exception_type' => $exception === null ? null : $exception::class,
            'message' => $exception?->getMessage()]);
    }
}
