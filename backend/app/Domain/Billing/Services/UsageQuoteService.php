<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Contracts\MediaDurationInspectorInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;

final class UsageQuoteService
{
    public function __construct(
        private readonly BillingRepositoryInterface $repository,
        private readonly BillingSettingsInterface $settings,
        private readonly TranscriberGatewayResolverInterface $resolver,
        private readonly MediaDurationInspectorInterface $media,
    ) {}

    public function create(int $userId, array $source, string $key): array
    {
        return $this->repository->exclusive('quote:'.$userId.':'.$key, function () use ($userId, $source, $key): array {
            $existing = $this->repository->quoteByClientKey($userId, $key);
            if ($existing) {
                if ($existing['request_source'] != $source) {
                    throw new BillingException('This quote reference belongs to different media.');
                }

                return $existing;
            }
            $provider = $this->resolver->resolve($source['language_code'])->provider();
            $model = $this->settings->model($provider);
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
        $this->repository->exclusive('measure:'.$id, function () use ($id): void {
            $quote = $this->repository->quote($id);
            if ($quote === null || $quote['status'] !== 'measuring') {
                return;
            }
            if (new \DateTimeImmutable($quote['expires_at']) <= new \DateTimeImmutable) {
                $this->fail($id);

                return;
            }
            $verified = $this->media->measure((int) $quote['user_id'], $quote['source'], $quote['id']);
            $quantity = $verified['duration_ms'];
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
}
