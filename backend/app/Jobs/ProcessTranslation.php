<?php

namespace App\Jobs;

use App\Domain\Translation\Contracts\TranslationRepositoryInterface;
use App\Domain\Translation\Services\ProcessTranslation as TranslationProcessor;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessTranslation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(public string $translationId, public string $outboxEventId, public int $revision = 0)
    {
        $this->onConnection('redis')->onQueue('transcriptions');
    }

    /**
     * Execute the job.
     */
    public function handle(TranslationProcessor $processor, TranslationRepositoryInterface $repository): void
    {
        $completedRevision = $processor->handle($this->translationId);
        if ($completedRevision !== null) {
            $this->acknowledge($completedRevision);
        }
    }

    public function uniqueId(): string
    {
        return 'translation:'.$this->translationId;
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    private function acknowledge(int $revision): void
    {
        DB::transaction(function () use ($revision): void {
            $event = OutboxEvent::query()->lockForUpdate()->find($this->outboxEventId);
            if ($event !== null && ($event->payload['revision'] ?? 0) <= $revision) {
                app(OutboxService::class)->markPublished($event->id);
            }
        });
    }

    public function failed(?\Throwable $exception): void
    {
        app(TranslationProcessor::class)->fail($this->translationId, $this->revision);
        $this->acknowledge($this->revision);
    }
}
