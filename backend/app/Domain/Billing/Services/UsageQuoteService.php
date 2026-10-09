<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Contracts\MediaDurationInspectorInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;
use App\Domain\Transcriber\Services\OpenAITranscriptionCapabilities;

final class UsageQuoteService
{
    public function __construct(
        private readonly BillingRepositoryInterface $repository,
        private readonly BillingSettingsInterface $settings,
        private readonly TranscriberGatewayResolverInterface $resolver,
        private readonly MediaDurationInspectorInterface $media,
        private readonly PrivacyCoordinatorInterface $privacy,
    ) {}

    public function create(int $userId, array $source, string $key): array
    {
        $this->assertSource($source['audio_storage_path'] ?? null);

        return $this->repository->exclusive('quote:'.$userId.':'.$key, function () use ($userId, $source, $key): array {
            $existing = $this->repository->quoteByClientKey($userId, $key);
            if ($existing) {
                if ($existing['request_source'] != $source) {
                    throw new BillingException('This quote reference belongs to different media.');
                }

                return $existing;
            }
            try {
                $provider = $this->resolver->resolve($source['language_code'])->provider();
            } catch (\InvalidArgumentException $exception) {
                throw new BillingException('The selected language is not supported by the configured speech model.', 422);
            }
            $model = $this->settings->model($provider);
            if ($provider === 'openai') {
                try {
                    OpenAITranscriptionCapabilities::format($model);
                } catch (\InvalidArgumentException) {
                    throw new BillingException('Transcription is not configured yet.', 503);
                }
                if (blank(config('openai.key'))) {
                    throw new BillingException('Transcription is not configured yet.', 503);
                }
            }
            $rate = $this->settings->rate('transcription', $provider, $model);

            return $this->repository->transaction(function () use ($userId, $source, $key, $provider, $model, $rate): array {
                $quote = $this->repository->createQuote([
                    'user_id' => $userId, 'client_key' => $key, 'activity' => 'transcription',
                    'provider' => $provider, 'model' => $model, 'rate' => $rate,
                    'source' => $source, 'request_source' => $source, 'status' => 'measuring', 'expires_at' => $this->settings->quoteExpiresAt(),
                ]);
                $this->repository->enqueue('billing:quote:'.$quote['id'], 'BillingQuoteRequested', $quote['id'], []);

                return $quote;
            });
        });
    }

    public function measure(string $id): void
    {
        $quote = $this->repository->quote($id);
        $path = $quote['source']['audio_storage_path'] ?? 'quote:'.$id;
        $this->privacy->exclusive('source', $path, fn () => $this->measureSource($id));
    }

    private function measureSource(string $id): void
    {
        $this->repository->exclusive('measure:'.$id, function () use ($id): void {
            $quote = $this->repository->quote($id);
            if ($quote === null || $quote['status'] !== 'measuring') {
                return;
            }
            if (new \DateTimeImmutable($quote['expires_at']) <= new \DateTimeImmutable) {
                $this->fail($id);

                return;
            }
            $this->assertSource($quote['source']['audio_storage_path'] ?? null);
            $verified = $this->media->measure((int) $quote['user_id'], $quote['source'], $quote['id']);
            if ($this->repository->quote($id)['status'] !== 'measuring') {
                return;
            }
            $quantity = $verified['duration_ms'];
            if ($quote['provider'] === 'google' && $quantity > (config('transcriber.google.word_timestamps', true) ? 1200000 : 3600000)) {
                throw new BillingException('This audio exceeds the selected speech model duration limit.', 422);
            }
            if ($quote['provider'] === 'openai' && $quantity > max(1, (int) config('transcriber.openai.max_duration_ms', 5400000))) {
                $minutes = (int) floor(config('transcriber.openai.max_duration_ms', 5400000) / 60000);
                $reason = "This transcription option supports recordings up to {$minutes} minutes long.";
                $this->repository->updateQuote($id, ['status' => 'failed', 'failure_reason' => $reason]);
                throw new BillingException($reason, 422);
            }
            $rate = $quote['rate'];
            $this->repository->updateQuote($id, [
                'quantity' => $quantity,
                'credit_units' => CreditMath::prorate((int) $rate['credit_units'], $quantity, (int) $rate['unit_length']),
                'status' => 'ready',
                'source' => [
                    'audio_url' => $verified['audio_url'], 'audio_storage_path' => $verified['audio_storage_path'],
                    'file_name' => $verified['file_name'], 'language_code' => $quote['source']['language_code'],
                ],
            ]);
        });
    }

    public function fail(string $id): void
    {
        $quote = $this->repository->quote($id);
        if ($quote !== null && $quote['status'] === 'measuring') {
            $this->repository->updateQuote($id, ['status' => 'failed', 'failure_reason' => 'We could not measure this media. Try another file or a direct audio link.']);
        }
    }

    private function assertSource(?string $path): void
    {
        if ($path !== null && ($this->privacy->sourceDeleted($path) || $this->privacy->sourceDeletionPending($path))) {
            throw new BillingException('This source file is unavailable or being deleted.', 410);
        }
    }
}
