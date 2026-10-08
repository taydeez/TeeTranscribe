<?php

namespace App\Domain\Dubbing\Services;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\DubbingGatewayInterface;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingRepositoryInterface;

final readonly class ProcessDubbing
{
    public function __construct(private DubbingRepositoryInterface $records, private BillingRepositoryInterface $billing,
        private DubbingGatewayInterface $gateway, private DubbingMediaInterface $media, private CreditService $credits) {}

    /** Returns true when the outbox event can be acknowledged; false means poll again. */
    public function handle(string $id): bool
    {
        return $this->billing->exclusive('dubbing:'.$id, function () use ($id): bool {
            $record = $this->records->find($id);
            if ($record === null || in_array($record->status, ['complete', 'failed'], true)) {
                return true;
            }
            if ($record->providerCompletedAt === null && $record->createdAt !== null && new \DateTimeImmutable($record->createdAt) < (new \DateTimeImmutable)->modify('-24 hours')) {
                $this->fail($id);

                return true;
            }
            if ($record->projectId === null) {
                if ($record->submissionStartedAt !== null) {
                    $project = $this->gateway->recover($record->id);
                    if ($project === null) {
                        throw new BillingException('The dubbing submission needs reconciliation before it can be retried.', 409);
                    }
                } else {
                    $sourceUrl = $this->media->sourceUrl($record->sourceStoragePath);
                    $record = $this->records->update($id, ['status' => 'processing', 'submission_started_at' => (new \DateTimeImmutable)->format(DATE_ATOM)]);
                    $project = $this->gateway->create($record, $sourceUrl);
                }
                $record = $this->records->update($id, ['provider_project_id' => $project['project_id'], 'provider_language_id' => $project['language_ids'][0] ?? null]);
            }
            $project = $this->gateway->project($record->projectId);
            if ($project['status'] === 'failed') {
                $this->fail($id);

                return true;
            }
            if ($record->languageId === null) {
                $languageId = $project['language_ids'][0] ?? null;
                if ($languageId === null) {
                    return false;
                }
                $record = $this->records->update($id, ['provider_language_id' => $languageId]);
            }
            $language = $this->gateway->language($record->projectId, $record->languageId);
            if (($language['target_language'] ?? null) !== $record->targetLanguage) {
                throw new BillingException('The dubbed language does not match this request.', 502);
            }
            if ($language['status'] === 'failed') {
                $this->fail($id);

                return true;
            }
            if ($language['status'] !== 'completed') {
                return false;
            }
            if (($language['output_revision'] ?? null) !== ($language['revision'] ?? null)) {
                throw new BillingException('Dubbing returned an outdated output.', 502);
            }
            if ($record->providerCompletedAt === null) {
                $record = $this->billing->transaction(function () use ($id) {
                    $record = $this->records->update($id, ['provider_completed_at' => (new \DateTimeImmutable)->format(DATE_ATOM)]);
                    $this->credits->consumeDubbing($id);

                    return $record;
                });
            }
            $paths = $this->media->store($record, $language['outputs']['lossless_audio'] ?? '');
            $this->records->update($id, $paths + ['status' => 'complete', 'failure_reason' => null]);

            return true;
        });
    }

    public function fail(string $id): void
    {
        $this->billing->transaction(function () use ($id): void {
            $record = $this->records->find($id, lock: true);
            if ($record === null || $record->status === 'complete') {
                return;
            }
            $this->records->update($id, ['status' => 'failed', 'failure_reason' => $record->providerCompletedAt === null
                ? 'Dubbing failed. Your reserved credits have been returned.'
                : 'Your dubbed audio is ready, but the video could not be saved. Retry the download preparation.']);
            if ($record->providerCompletedAt === null) {
                $this->credits->releaseDubbing($id);
            }
        });
    }

    public function retryExports(string $id, int $userId): void
    {
        $this->billing->transaction(function () use ($id, $userId): void {
            $record = $this->records->find($id, $userId, lock: true) ?? throw new BillingException('Dubbing not found.', 404);
            if ($record->status !== 'failed' || $record->providerCompletedAt === null) {
                return;
            }
            $this->records->update($id, ['status' => 'processing', 'failure_reason' => null]);
            $this->records->enqueueExports($id);
        });
    }
}
