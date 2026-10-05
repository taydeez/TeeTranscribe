<?php

namespace App\Jobs;

use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class GenerateTranscriptionExports implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(
        public string $transcriptionId,
        public ?string $outboxEventId = null,
    ) {
        $this->onConnection('redis');
        $this->onQueue('exports');
    }

    public function uniqueId(): string
    {
        return $this->transcriptionId;
    }

    public function handle(OutboxService $outbox): void
    {
        $transcription = Transcription::query()->findOrFail($this->transcriptionId);

        if ($transcription->status === 'complete') {
            $this->markOutboxPublished($outbox);

            return;
        }

        if (! in_array($transcription->status, ['processing', 'failed'], true)) {
            throw new RuntimeException('Transcription is not ready for export.');
        }

        foreach (['txt', 'pdf'] as $format) {
            TranscriptionExport::query()->firstOrCreate(
                ['transcription_id' => $transcription->id, 'format' => $format],
                ['status' => 'pending'],
            );
        }

        GenerateTxtExport::dispatchSync($transcription->id);
        GeneratePdfExport::dispatchSync($transcription->id);

        $exports = $transcription->exports()->get()->keyBy('format');
        if (! $exports->has('txt') || ! $exports->has('pdf')
            || $exports['txt']->status !== 'completed' || blank($exports['txt']->storage_path)
            || $exports['pdf']->status !== 'completed' || blank($exports['pdf']->storage_path)) {
            throw new RuntimeException('Both transcription exports were not completed.');
        }

        $this->markOutboxPublished($outbox);
    }

    private function markOutboxPublished(OutboxService $outbox): void
    {
        $eventId = $this->outboxEventId;

        if ($eventId === null) {
            $eventId = $outbox->findCompletionEventId($this->transcriptionId);
        }

        if ($eventId !== null) {
            $outbox->markPublished($eventId);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        app(TranscriptionOutcomePublisher::class)->failed($this->transcriptionId);
    }
}
