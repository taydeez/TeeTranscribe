<?php

namespace App\Jobs;

use App\Domain\Dubbing\Services\ProcessDubbingSubtitles;
use App\Infrastructure\Outbox\OutboxService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RenderDubbingSubtitles implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 7200;

    public int $uniqueFor = 7500;

    public function __construct(public string $dubbingId, public string $outboxEventId)
    {
        $this->onConnection('subtitle_rendering')->onQueue('subtitle-rendering');
    }

    public function uniqueId(): string
    {
        return 'dubbing:subtitles:'.$this->dubbingId;
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ProcessDubbingSubtitles $processor, OutboxService $outbox): void
    {
        $processor->handle($this->dubbingId);
        $outbox->markPublished($this->outboxEventId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(ProcessDubbingSubtitles::class)->fail($this->dubbingId);
        app(OutboxService::class)->markPublished($this->outboxEventId);
        Log::warning('Dubbing subtitle rendering exhausted retries.', ['dubbing_id' => $this->dubbingId,
            'exception_type' => $exception === null ? null : $exception::class, 'message' => $exception?->getMessage()]);
    }
}
