<?php

namespace App\Jobs;

use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Transcriber\Services\FinalizeTranscriptionExports;
use App\Domain\Transcriber\Services\TranscriptionExportOptions;
use App\Infrastructure\Exports\TranscriptionExportGenerator;
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

    public int $revision = 0;

    public function __construct(
        public string $transcriptionId,
        public ?string $outboxEventId = null,
    ) {
        $this->revision = Transcription::find($transcriptionId)?->export_revision ?? 0;
        $this->onConnection('redis');
        $this->onQueue('exports');
    }

    public function uniqueId(): string
    {
        return $this->transcriptionId;
    }

    public function handle(OutboxService $outbox): void
    {
        app(PrivacyCoordinatorInterface::class)->exclusive('transcription', $this->transcriptionId, function () use ($outbox): void {
            if (app(PrivacyCoordinatorInterface::class)->projectDeleted('transcription', $this->transcriptionId)) {
                $this->markOutboxPublished($outbox);

                return;
            }
            $this->generate($outbox);
        });
    }

    private function generate(OutboxService $outbox): void
    {
        $transcription = Transcription::query()->findOrFail($this->transcriptionId);
        if ($transcription->export_revision !== $this->revision) {
            return;
        }

        if ($transcription->status === 'complete') {
            $this->markOutboxPublished($outbox);

            return;
        }

        if (! in_array($transcription->status, ['processing', 'failed'], true)) {
            throw new RuntimeException('Transcription is not ready for export.');
        }

        $options = array_values(array_filter(TranscriptionExportOptions::required($transcription->segments ?? []),
            fn (array $option): bool => ! app(PrivacyCoordinatorInterface::class)->generatedDeleted('transcription', $this->transcriptionId, $option['format'])));
        foreach ($options as $option) {
            TranscriptionExport::query()->firstOrCreate(
                ['transcription_id' => $transcription->id, ...$option],
                ['status' => 'pending', 'export_revision' => $transcription->export_revision],
            );
        }

        $revision = $transcription->export_revision;
        foreach ($options as $option) {
            app(TranscriptionExportGenerator::class)->generate($transcription->id, $option['format'], $revision, $option['variant']);
        }
        app(FinalizeTranscriptionExports::class)->handle($transcription->id);
        if (app(PrivacyCoordinatorInterface::class)->projectDeleted('transcription', $this->transcriptionId)) {
            $this->markOutboxPublished($outbox);

            return;
        }
        if ($transcription->refresh()->export_revision !== $revision) {
            return;
        }

        $exports = $transcription->exports()->where('export_revision', $revision)->get()->keyBy(fn ($export) => $export->format.':'.$export->variant);
        foreach ($options as $option) {
            $export = $exports->get($option['format'].':'.$option['variant']);
            if ($export === null || $export->status !== 'completed' || blank($export->storage_path)) {
                throw new RuntimeException('All transcription exports were not completed.');
            }
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
        app(TranscriptionExportGenerator::class)->failed($this->transcriptionId, $this->revision);
    }
}
