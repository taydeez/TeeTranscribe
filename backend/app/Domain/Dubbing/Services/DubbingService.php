<?php

namespace App\Domain\Dubbing\Services;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditMath;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingRepositoryInterface;
use App\Domain\Dubbing\Entities\Dubbing;

final readonly class DubbingService
{
    public function __construct(private DubbingRepositoryInterface $records, private BillingRepositoryInterface $billing,
        private BillingSettingsInterface $settings, private CreditService $credits, private DubbingLanguages $languages,
        private DubbingMediaInterface $media) {}

    public function quote(int $userId, array $source, string $key): array
    {
        return $this->billing->exclusive('quote:'.$userId.':'.$key, function () use ($userId, $source, $key): array {
            $existing = $this->billing->quoteByClientKey($userId, $key);
            if ($existing !== null) {
                if ($existing['activity'] !== 'dubbing' || $existing['request_source'] != $source) {
                    throw new BillingException('This reference belongs to a different request.', 409);
                }

                return $existing;
            }
            $this->languages->validate($source['source_language'] ?? null, $source['target_language']);
            $upload = $this->records->completedUpload($userId, $source['video_storage_path']);
            if ($upload === null) {
                throw new BillingException('Choose a completed video upload belonging to your account.', 404);
            }
            $rate = $this->settings->rate('dubbing', 'elevenlabs', 'dubbing_v2');

            return $this->billing->transaction(function () use ($userId, $source, $key, $rate): array {
                $quote = $this->billing->createQuote(['user_id' => $userId, 'client_key' => $key, 'activity' => 'dubbing',
                    'provider' => 'elevenlabs', 'model' => 'dubbing_v2', 'rate' => $rate, 'source' => $source, 'request_source' => $source,
                    'status' => 'measuring', 'expires_at' => $this->settings->quoteExpiresAt()]);
                $this->billing->enqueue('dubbing:quote:'.$quote['id'], 'DubbingQuoteRequested', $quote['id'], []);

                return $quote;
            });
        });
    }

    public function measure(string $id): void
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
            $upload = $this->records->completedUpload($quote['user_id'], $source['video_storage_path']);
            if ($upload === null) {
                throw new BillingException('The uploaded video is unavailable.', 422);
            }
            $verified = $this->media->inspect($source['video_storage_path']);
            if ($verified['size'] !== $upload['size']) {
                throw new BillingException('The uploaded video is incomplete.', 422);
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
            $this->billing->updateQuote($id, ['status' => 'failed', 'failure_reason' => 'This video could not be inspected. Use an MP4 or WebM with a spoken audio track.']);
        }
    }

    public function submit(int $userId, string $quoteId): Dubbing
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
            $record = $this->records->create(['user_id' => $userId, 'name' => $source['name'] ?? pathinfo($source['file_name'], PATHINFO_FILENAME),
                'source_storage_path' => $source['video_storage_path'], 'source_language' => $source['source_language'] ?? null,
                'target_language' => $source['target_language'], 'duration_ms' => $quote['quantity'], 'status' => 'pending']);
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
}
