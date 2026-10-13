<?php

namespace App\Domain\Admin\Billing\Services;

use App\Domain\Admin\Billing\Contracts\BillingConfigurationRepositoryInterface;
use App\Domain\Billing\Contracts\BillingConfigurationInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditMath;

final readonly class BillingConfigurationService
{
    public function __construct(private BillingConfigurationRepositoryInterface $repository, private BillingConfigurationInterface $settings) {}

    public function record(): array
    {
        $record = $this->repository->find() ?? ['version' => 0, 'updatedAt' => null];
        $record['configuration'] = $this->settings->current();

        return $record
            + ['credit_value_ngn' => '1', 'history' => $this->repository->history(),
                'activity_units' => ['transcription' => 'minute', 'translation' => '1000_characters', 'video_dubbing' => 'minute',
                    'audio_dubbing' => 'minute', 'subtitles' => 'minute', 'cleanup' => '1000_characters', 'summary' => '1000_characters']];
    }

    public function update(array $input, int $actorId): array
    {
        $configuration = $input['configuration'];
        $configuration['concurrency'] ??= $this->settings->current()['concurrency'];
        foreach ($configuration['packages'] as $package) {
            $this->amount($package['credits'], true);
            $this->amount($package['price_ngn'], true);
        }
        $this->amount($configuration['free_credits']);
        foreach ($configuration['minimum_checkout'] as $amount) {
            $this->amount($amount, true);
        }
        if ($configuration['fx']['ngn_per_usd'] !== null) {
            $rate = CreditMath::decimal($configuration['fx']['ngn_per_usd'], 6);
            if ($rate < 1 || $rate > 1000000000000) {
                throw new BillingException('Choose a positive exchange rate up to 1,000,000 NGN per USD.', 422);
            }
        }
        foreach ($configuration['activities'] as $pricing) {
            if ($pricing['mode'] === 'activity') {
                $this->amount($pricing['credits'], true);
            }
        }
        $this->repository->save($configuration, $this->settings->current(), $input['version'], $actorId, trim($input['reason']));

        return $this->record();
    }

    private function amount(?string $amount, bool $positive = false): void
    {
        $units = CreditMath::decimal($amount);
        if ($units > 100000000 || ($positive && $units < 1)) {
            throw new BillingException('Choose an amount within the supported billing limits.', 422);
        }
    }
}
