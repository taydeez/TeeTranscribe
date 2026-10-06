<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Folder\Services\FolderService;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Entities\Transcription;
use App\Domain\Transcriber\Services\TranscribeService;
use DateTimeImmutable;

final class PaidTranscriptionService
{
    public function __construct(
        private readonly BillingRepositoryInterface $repository,
        private readonly CreditService $credits,
        private readonly TranscribeService $transcriptions,
        private readonly TranscriptionRepositoryInterface $transcriptionRepository,
        private readonly FolderService $folders,
        private readonly BillingSettingsInterface $settings,
    ) {}

    public function submit(int $userId, string $quoteId, ?string $folderId): Transcription
    {
        return $this->repository->transaction(function () use ($userId, $quoteId, $folderId): Transcription {
            $quote = $this->repository->quote($quoteId, $userId, lock: true)
                ?? throw new BillingException('Quote not found.', 404);
            if ($quote['transcription_id'] !== null) {
                return $this->transcriptionRepository->find($quote['transcription_id'])
                    ?? throw new BillingException('The submitted transcription no longer exists.', 410);
            }
            if ($quote['status'] !== 'ready' || new DateTimeImmutable($quote['expires_at']) <= new DateTimeImmutable) {
                throw new BillingException('This quote is not ready or has expired. Request a new quote.');
            }
            if ($this->settings->model($quote['provider']) !== $quote['model']) {
                throw new BillingException('The speech model changed. Request a new quote.');
            }
            $folder = $folderId !== null
                ? $this->folders->findOrFail($folderId, $userId)
                : $this->folders->findOrCreateByName($userId, (new DateTimeImmutable)->format('F j, Y'));
            $source = $quote['source'];
            $source['user_id'] = $userId;
            $source['duration'] = $quote['quantity'] / 1000;
            $transcription = $this->transcriptions->startNewTranscription($source, dispatch: false);
            if ($transcription->provider !== $quote['provider']) {
                throw new BillingException('The transcription provider changed. Request a new quote.');
            }
            $this->credits->reserve($userId, $quote, $transcription->id);
            $this->folders->attachTranscription($folder->id, $userId, $transcription->id);
            $this->repository->updateQuote($quoteId, ['status' => 'submitted', 'transcription_id' => $transcription->id]);
            $this->repository->enqueue(
                'transcription:'.$transcription->id.':submitted', 'TranscriptionSubmitted', $transcription->id,
                ['language_code' => $source['language_code'], 'model' => $quote['model']],
            );

            return $transcription;
        });
    }
}
