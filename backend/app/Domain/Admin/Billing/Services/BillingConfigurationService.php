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
            + ['credit_value_usd' => '0.01', 'history' => $this->repository->history(),
                'activity_units' => ['transcription' => 'minute', 'translation' => '1000_characters', 'video_dubbing' => 'minute',
                    'audio_dubbing' => 'minute', 'subtitles' => 'minute', 'cleanup' => '1000_characters', 'summary' => '1000_characters']];
    }

    public function update(array $input, int $actorId): array
    {
        $configuration = $input['configuration'];
        $configuration['concurrency'] ??= $this->settings->current()['concurrency'];
        $configuration['task_deadlines'] ??= $this->settings->current()['task_deadlines'];
        $configuration['gateways']['bachs'] ??= $this->settings->current()['gateways']['bachs'];
        foreach ($configuration['packages'] as $package) {
            $this->amount($package['credits'], true);
            $this->amount($package['price_usd'], true);
            if (isset($package['price_ngn'])) {
                $this->amount($package['price_ngn'], true);
            }
        }
        $this->amount($configuration['free_credits']);
        foreach ($configuration['minimum_checkout'] as $amount) {
            $this->amount($amount, true);
        }
        unset($configuration['fx']);
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
