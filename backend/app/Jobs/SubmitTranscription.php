<?php

namespace App\Jobs;

use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;
use App\Domain\Transcriber\Contracts\TranscriptionPollingDispatcherInterface;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Outbox\OutboxService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class SubmitTranscription implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 7200;

    public function __construct(
        public string $transcriptionId,
        public string $languageCode,
        public ?string $outboxEventId = null,
        public ?string $quotedModel = null,
    ) {
        $this->onConnection('redis')->onQueue('ingestion');
    }

    public function uniqueId(): string
    {
        return $this->transcriptionId;
    }

    public function handle(
        TranscriberGatewayResolverInterface $resolver,
        TranscriptionRepositoryInterface $repository,
        TranscriptionPollingDispatcherInterface $pollingDispatcher,
    ): void {
        $transcription = $repository->find($this->transcriptionId)
            ?? throw new RuntimeException('The transcription no longer exists.');

        if ($transcription->status !== 'pending') {
            $this->markPublished();

            return;
        }

        if ($transcription->providerRequestId === null) {
            $gateway = $resolver->resolve($this->languageCode);
            if ($gateway->provider() !== $transcription->provider) {
                throw new RuntimeException('The configured transcription provider changed after submission.');
            }
            if ($this->quotedModel !== null && $gateway->provider() === 'deepgram'
                && config('transcriber.deepgram.model', 'nova-2') !== $this->quotedModel) {
                throw new RuntimeException('The quoted speech model is no longer configured.');
            }

            $providerRequestId = $gateway->transcribe(
                $transcription->audioPath,
                $this->languageCode,
                $transcription->id,
                $transcription->duration,
                $transcription->audioStoragePath,
                $transcription->fileName,
            );

            $transcription = $repository->update($transcription->id, [
                'provider_request_id' => $providerRequestId,
            ]);
        }

        $pollingDispatcher->dispatch($transcription);
        $this->markPublished();
    }

    public function failed(?Throwable $exception): void
    {
        app(TranscriptionOutcomePublisher::class)->failed($this->transcriptionId, pendingOnly: true);
        $this->markPublished();
    }

    private function markPublished(): void
    {
        if ($this->outboxEventId !== null) {
            app(OutboxService::class)->markPublished($this->outboxEventId);
        }
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }
}
