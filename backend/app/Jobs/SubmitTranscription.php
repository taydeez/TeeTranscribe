<?php

namespace App\Jobs;

use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;
use App\Domain\Transcriber\Contracts\TranscriptionPollingDispatcherInterface;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Infrastructure\AI\Transcriber\Google\GoogleSpeechAudioStorage;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Outbox\OutboxService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
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
        app(PrivacyCoordinatorInterface::class)->exclusive('transcription', $this->transcriptionId, function () use ($resolver, $repository, $pollingDispatcher): void {
            if (app(PrivacyCoordinatorInterface::class)->projectDeleted('transcription', $this->transcriptionId)) {
                $this->markPublished();

                return;
            }
            $this->submit($resolver, $repository, $pollingDispatcher);
        });
    }

    private function submit(TranscriberGatewayResolverInterface $resolver, TranscriptionRepositoryInterface $repository,
        TranscriptionPollingDispatcherInterface $pollingDispatcher): void
    {
        $transcription = $repository->find($this->transcriptionId)
            ?? throw new RuntimeException('The transcription no longer exists.');

        if ($transcription->audioStoragePath !== null && app(PrivacyCoordinatorInterface::class)->sourceDeleted($transcription->audioStoragePath)) {
            app(TranscriptionOutcomePublisher::class)->failed($this->transcriptionId, pendingOnly: true);
            $this->markPublished();

            return;
        }
        if ($transcription->status !== 'pending') {
            $this->markPublished();

            return;
        }

        if ($transcription->providerRequestId === null) {
            $gateway = $resolver->resolve($this->languageCode, $transcription->provider);
            if ($gateway->provider() !== $transcription->provider) {
                throw new RuntimeException('The configured transcription provider changed after submission.');
            }

            $providerRequestId = $gateway->transcribe(
                $transcription->audioPath,
                $this->languageCode,
                $transcription->id,
                $transcription->duration,
                $transcription->audioStoragePath,
                $transcription->fileName,
                $this->quotedModel,
            );

            if (app(PrivacyCoordinatorInterface::class)->projectDeleted('transcription', $this->transcriptionId)) {
                $this->markPublished();

                return;
            }
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
        $transcription = app(TranscriptionRepositoryInterface::class)->find($this->transcriptionId);
        if ($transcription?->provider === 'google') {
            try {
                app(GoogleSpeechAudioStorage::class)->remove($this->transcriptionId);
            } catch (Throwable $cleanupException) {
                Log::warning('Google transcription staging cleanup failed.', ['transcription_id' => $this->transcriptionId, 'exception_type' => $cleanupException::class]);
            }
        }
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
