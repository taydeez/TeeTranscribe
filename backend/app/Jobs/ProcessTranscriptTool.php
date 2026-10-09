<?php

namespace App\Jobs;

use App\Domain\Transcriber\Services\ProcessTranscriptTool as ToolProcessor;
use App\Infrastructure\Outbox\OutboxService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessTranscriptTool implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 1200;

    public function __construct(public string $toolId, public string $outboxEventId)
    {
        $this->onConnection('redis')->onQueue('transcriptions');
    }

    public function handle(ToolProcessor $processor): void
    {
        $processor->handle($this->toolId);
        app(OutboxService::class)->markPublished($this->outboxEventId);
    }

    public function uniqueId(): string
    {
        return 'transcript-tool:'.$this->toolId;
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function failed(?\Throwable $exception): void
    {
        Log::warning('Transcript tool exhausted retries.', ['tool_id' => $this->toolId, 'exception_type' => $exception !== null ? $exception::class : null]);
        app(ToolProcessor::class)->fail($this->toolId);
        app(OutboxService::class)->markPublished($this->outboxEventId);
    }
}
