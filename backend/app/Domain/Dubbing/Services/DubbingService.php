<?php

namespace App\Domain\Dubbing\Services;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditMath;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\AudioDubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingGatewayResolverInterface;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingRepositoryInterface;
use App\Domain\Dubbing\Entities\Dubbing;
use App\Domain\Folder\Services\FolderService;
use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;

final readonly class DubbingService
{
    public function __construct(private DubbingRepositoryInterface $records, private BillingRepositoryInterface $billing,
        private BillingSettingsInterface $settings, private CreditService $credits, private DubbingLanguages $languages,
        private DubbingMediaInterface $media, private DubbingGatewayResolverInterface $providers, private VideoSubtitleLanguages $videoSubtitles,
        private AudioDubbingMediaInterface $audio, private FolderService $folders, private PrivacyCoordinatorInterface $privacy) {}

    public function quote(int $userId, array $source, string $key): array
    {
        $this->assertSource($this->sourcePath($source));
        if (! empty($source['folder_id'])) {
            $this->folders->findOrFail($source['folder_id'], $userId);
        }

        return $this->billing->exclusive('quote:'.$userId.':'.$key, function () use ($userId, $source, $key): array {
            $existing = $this->billing->quoteByClientKey($userId, $key);
            if ($existing !== null) {
                if ($existing['activity'] !== 'dubbing' || $existing['request_source'] != $source) {
                    throw new BillingException('This reference belongs to a different request.', 409);
                }

                return $existing;
            }
            $subtitleOnly = ($source['operation'] ?? 'dubbing') === 'subtitles';
            $audioOnly = ($source['media_type'] ?? 'video') === 'audio';
            if ($audioOnly && ($subtitleOnly || ! empty($source['subtitles_enabled']))) {
                throw new BillingException('Subtitles require a video upload.', 422);
            }
            $definition = $subtitleOnly ? $this->videoSubtitles->validate($source['source_language'] ?? null, $source['target_language'])
                : ($audioOnly ? $this->providers->definition('audio') : $this->providers->definition());
            if (! $definition['configured']) {
                throw new BillingException('Dubbing is not configured yet.', 503);
            }
            if (! $subtitleOnly) {
                $this->languages->validate($source['source_language'] ?? null, $source['target_language'], $audioOnly ? 'audio' : 'video');
            }
            $upload = $this->records->completedUpload($userId, $this->sourcePath($source));
            if ($upload === null) {
                throw new BillingException('Choose a completed upload belonging to your account.', 404);
            }
            if ($audioOnly && ! str_starts_with($upload['content_type'], 'audio/')) {
                throw new BillingException('Choose an audio upload for audio dubbing.', 422);
            }
            $rate = $this->settings->rate($subtitleOnly ? 'subtitles' : 'dubbing', $definition['provider'], $definition['model']);

            return $this->billing->transaction(function () use ($userId, $source, $key, $rate, $definition): array {
                $quote = $this->billing->createQuote(['user_id' => $userId, 'client_key' => $key, 'activity' => 'dubbing',
                    'provider' => $definition['provider'], 'model' => $definition['model'], 'rate' => $rate,
                    'source' => $source + ['provider_options' => $definition['options'] ?? []], 'request_source' => $source,
                    'status' => 'measuring', 'expires_at' => $this->settings->quoteExpiresAt()]);
                $this->billing->enqueue('dubbing:quote:'.$quote['id'], 'DubbingQuoteRequested', $quote['id'], []);

                return $quote;
            });
        });
    }

    public function measure(string $id): void
    {
        $quote = $this->billing->quote($id);
        if ($quote === null || $quote['status'] !== 'measuring') {
            return;
        }
        $this->privacy->exclusive('source', $this->sourcePath($quote['source']), fn () => $this->measureSource($id));
    }

    private function measureSource(string $id): void
    {
        $this->billing->exclusive('measure:'.$id, function () use ($id): void {
            $quote = $this->billing->quote($id);
            if ($quote === null || $quote['activity'] !== 'dubbing' || $quote['status'] !== 'measuring') {
                return;
            }
            if (new \DateTimeImmutable($quote['expires_at']) <= new \DateTimeImmutable) {
                $this->failQuote($id);

                return;
            }
            $source = $quote['source'];
            $this->assertSource($this->sourcePath($source));
            $upload = $this->records->completedUpload($quote['user_id'], $this->sourcePath($source));
            if ($upload === null) {
                throw new BillingException('The uploaded media is unavailable.', 422);
            }
            $verified = ($source['media_type'] ?? 'video') === 'audio' ? $this->audio->inspect($this->sourcePath($source))
                : $this->media->inspect($this->sourcePath($source));
            if ($verified['size'] !== $upload['size']) {
                throw new BillingException('The uploaded media is incomplete.', 422);
            }
            if ($this->billing->quote($id)['status'] !== 'measuring') {
                return;
            }
            $this->billing->updateQuote($id, ['status' => 'ready', 'quantity' => $verified['duration_ms'],
                'credit_units' => CreditMath::prorate($quote['rate']['credit_units'], $verified['duration_ms'], $quote['rate']['unit_length']),
                'source' => $source + ['file_name' => $upload['filename']]]);
        });
    }

    public function failQuote(string $id): void
    {
        $quote = $this->billing->quote($id);
        if ($quote !== null && $quote['activity'] === 'dubbing' && $quote['status'] === 'measuring') {
            $this->billing->updateQuote($id, ['status' => 'failed', 'failure_reason' => ($quote['source']['media_type'] ?? 'video') === 'audio'
                ? 'This audio could not be inspected. Try another supported audio file.'
                : 'This video could not be inspected. Use an MP4 or WebM with a spoken audio track.']);
        }
    }

    public function submit(int $userId, string $quoteId): Dubbing
    {
        $quote = $this->billing->quote($quoteId, $userId) ?? throw new BillingException('Quote not found.', 404);
        $path = ! empty($quote['source']) ? $this->sourcePath($quote['source']) : 'quote:'.$quoteId;

        return $this->privacy->exclusive('source', $path, fn (): Dubbing => $this->confirm($userId, $quoteId));
    }

    private function confirm(int $userId, string $quoteId): Dubbing
    {
        return $this->billing->transaction(function () use ($userId, $quoteId): Dubbing {
            $quote = $this->billing->quote($quoteId, $userId, lock: true) ?? throw new BillingException('Quote not found.', 404);
            if ($quote['activity'] !== 'dubbing') {
                throw new BillingException('Choose a dubbing quote.', 422);
            }
            if (! empty($quote['dubbing_id'])) {
                return $this->find($quote['dubbing_id'], $userId);
            }
            if ($quote['status'] !== 'ready' || new \DateTimeImmutable($quote['expires_at']) <= new \DateTimeImmutable) {
                throw new BillingException('Check the price again before confirming.', 422);
            }
            $source = $quote['source'];
            $this->assertSource($this->sourcePath($source));
            $subtitlesEnabled = ($source['operation'] ?? 'dubbing') === 'subtitles' || ! empty($source['subtitles_enabled']);
            $record = $this->records->create(['user_id' => $userId, 'name' => $source['name'] ?? pathinfo($source['file_name'], PATHINFO_FILENAME),
                'folder_id' => $this->folders->resolveForUser($userId, $source['folder_id'] ?? null)->id,
                'source_storage_path' => $this->sourcePath($source), 'source_language' => $source['source_language'] ?? null,
                'media_type' => $source['media_type'] ?? 'video',
                'target_language' => $source['target_language'], 'duration_ms' => $quote['quantity'], 'status' => 'pending',
                'provider' => $quote['provider'], 'model' => $quote['model'], 'provider_options' => $source['provider_options'] ?? [],
                'operation' => $source['operation'] ?? 'dubbing', 'subtitles_enabled' => $subtitlesEnabled,
                'subtitle_style' => $subtitlesEnabled ? ($source['subtitle_style'] ?? 'classic') : null,
                'subtitle_status' => $subtitlesEnabled ? 'pending' : null]);
            $this->credits->reserve($userId, $quote, $record->id);
            $this->billing->updateQuote($quoteId, ['status' => 'submitted', 'dubbing_id' => $record->id]);
            $this->billing->enqueue('dubbing:'.$record->id.':process', 'DubbingRequested', $record->id, []);

            return $record;
        });
    }

    public function find(string $id, int $userId): Dubbing
    {
        return $this->records->find($id, $userId) ?? throw new BillingException('Dubbing not found.', 404);
    }

    public function history(int $userId, int $page, int $perPage): array
    {
        return $this->records->history($userId, $page, $perPage);
    }

    private function sourcePath(array $source): string
    {
        return ($source['media_type'] ?? 'video') === 'audio' ? $source['audio_storage_path'] : $source['video_storage_path'];
    }

    private function assertSource(string $path): void
    {
        if ($this->privacy->sourceDeleted($path) || $this->privacy->sourceDeletionPending($path)) {
            throw new BillingException('This source file is unavailable or being deleted.', 410);
        }
    }
}
