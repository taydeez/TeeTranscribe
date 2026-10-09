<?php

namespace App\Domain\Dubbing\Services;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\AudioDubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingGatewayResolverInterface;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingRepositoryInterface;
use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;

final readonly class ProcessDubbing
{
    public function __construct(private DubbingRepositoryInterface $records, private BillingRepositoryInterface $billing,
        private DubbingGatewayResolverInterface $providers, private DubbingMediaInterface $media, private CreditService $credits,
        private PrepareVideoSubtitles $videoSubtitles, private AudioDubbingMediaInterface $audio, private PrivacyCoordinatorInterface $privacy) {}

    /** Returns true when the outbox event can be acknowledged; false means poll again. */
    public function handle(string $id): bool
    {
        return $this->privacy->exclusive('dubbing', $id, function () use ($id): bool {
            if ($this->privacy->projectDeleted('dubbing', $id)) {
                return true;
            }

            return $this->process($id);
        });
    }

    private function process(string $id): bool
    {
        return $this->billing->exclusive('dubbing:'.$id, function () use ($id): bool {
            $record = $this->records->find($id);
            if ($record === null || in_array($record->status, ['complete', 'failed'], true)) {
                return true;
            }
            if ($this->privacy->sourceDeleted($record->sourceStoragePath)) {
                $this->fail($id);

                return true;
            }
            if ($record->subtitlesEnabled && $record->videoStoragePath !== null && $record->audioStoragePath !== null) {
                $this->records->enqueueSubtitles($id);

                return true;
            }
            if ($record->providerCompletedAt === null && $record->createdAt !== null && new \DateTimeImmutable($record->createdAt) < (new \DateTimeImmutable)->modify('-24 hours')) {
                $this->fail($id);

                return true;
            }
            if ($record->operation === 'subtitles') {
                $this->videoSubtitles->handle($record);

                return true;
            }
            if ($record->projectId === null) {
                $gateway = $this->providers->resolve($record->provider);
                if ($record->submissionStartedAt !== null) {
                    $project = $gateway->recover($record->id);
                    if ($project === null) {
                        throw new BillingException('The dubbing submission needs reconciliation before it can be retried.', 409);
                    }
                } else {
                    $sourceUrl = $this->media->sourceUrl($record->sourceStoragePath);
                    $record = $this->records->update($id, ['status' => 'processing', 'submission_started_at' => (new \DateTimeImmutable)->format(DATE_ATOM)]);
                    $project = $gateway->create($record, $sourceUrl);
                }
                if ($this->privacy->projectDeleted('dubbing', $id)) {
                    return true;
                }
                $record = $this->records->update($id, ['provider_project_id' => $project['project_id'], 'provider_language_id' => $project['language_ids'][0] ?? null]);
            }
            $gateway = $this->providers->resolve($record->provider);
            $project = $gateway->project($record->projectId);
            if ($this->privacy->projectDeleted('dubbing', $id)) {
                return true;
            }
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
            $language = $gateway->language($record->projectId, $record->languageId);
            if ($this->privacy->projectDeleted('dubbing', $id)) {
                return true;
            }
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
            if ($this->privacy->projectDeleted('dubbing', $id)) {
                return true;
            }
            $paths = $record->mediaType === 'audio' ? $this->audio->store($record, $language['outputs']['lossless_audio'] ?? '')
                : (isset($language['outputs']['video']) ? $this->media->storeVideo($record, $language['outputs']['video'])
                    : $this->media->store($record, $language['outputs']['lossless_audio'] ?? ''));
            $this->billing->transaction(function () use ($id, $paths, $record): void {
                if ($this->privacy->projectDeleted('dubbing', $id)) {
                    return;
                }
                $this->records->update($id, $paths + ['status' => $record->subtitlesEnabled ? 'processing' : 'complete', 'failure_reason' => null]);
                if ($record->subtitlesEnabled) {
                    $this->records->enqueueSubtitles($id);
                }
            });

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
            $this->records->update($id, ['status' => 'failed', 'failure_reason' => $record->operation === 'subtitles'
                ? ($record->providerCompletedAt === null ? 'Subtitle preparation failed. Your reserved credits have been returned.'
                    : 'Your subtitles are ready, but the video could not be saved. Retry download preparation without paying again.')
                : ($record->providerCompletedAt === null ? 'Dubbing failed. Your reserved credits have been returned.'
                    : 'Your dub is ready, but its downloads could not be saved. Retry the download preparation.')]);
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
            if ($record->subtitlesEnabled && $record->videoStoragePath !== null && $record->audioStoragePath !== null) {
                $this->records->update($id, ['subtitle_status' => 'pending']);
                $this->records->enqueueSubtitles($id, retry: true);
            } else {
                $this->records->enqueueExports($id);
            }
        });
    }
}
