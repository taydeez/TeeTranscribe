<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Entities\Wallet;
use App\Domain\Billing\Exceptions\BillingException;

final class CreditService
{
    public function __construct(
        private readonly BillingRepositoryInterface $repository,
        private readonly BillingSettingsInterface $settings,
    ) {}

    public function balance(int $userId): array
    {
        return $this->repository->transaction(function () use ($userId): array {
            $wallet = $this->wallet($userId);

            return ['available_units' => $wallet->available, 'reserved_units' => $wallet->reserved, 'units_per_credit' => 100];
        });
    }

    public function purchase(int $userId, int $units, string $reference): void
    {
        $this->repository->transaction(function () use ($userId, $units, $reference): void {
            $wallet = $this->wallet($userId);
            $key = 'payment:'.$reference;
            if ($this->repository->entryExists($key)) {
                return;
            }
            if ($units < 1) {
                throw new BillingException('Invalid purchased credits.', 422);
            }
            $wallet->available += $units;
            $this->repository->saveWallet($wallet);
            $this->repository->appendEntry($wallet, $key, 'purchase', $units, ['reference' => $reference]);
        });
    }

    public function reserve(int $userId, array $quote, string $transcriptionId): void
    {
        $wallet = $this->wallet($userId);
        $units = (int) $quote['credit_units'];
        if ($wallet->available < $units) {
            throw new BillingException('You do not have enough credits. Add credits before processing.', 402);
        }
        $wallet->available -= $units;
        $wallet->reserved += $units;
        $this->repository->saveWallet($wallet);
        $subjectKey = match ($quote['activity']) {
            'translation' => 'translation_id', 'dubbing' => 'dubbing_id', 'cleanup', 'summary' => 'transcript_tool_id', default => 'transcription_id'
        };
        $this->repository->createCharge([
            'user_id' => $userId, 'quote_id' => $quote['id'], $subjectKey => $transcriptionId,
            'activity' => $quote['activity'], 'provider' => $quote['provider'], 'model' => $quote['model'],
            'quantity' => $quote['quantity'], 'credit_units' => $units, 'rate' => $quote['rate'],
            'status' => 'reserved',
        ]);
        $this->repository->appendEntry($wallet, 'usage:'.$quote['id'].':reserve', 'reserve', $units, [$subjectKey => $transcriptionId]);
    }

    public function consume(string $transcriptionId, ?int $actualDurationMs = null): void
    {
        $this->settle($transcriptionId, 'consume', $actualDurationMs);
    }

    public function release(string $transcriptionId): void
    {
        $this->settle($transcriptionId, 'release');
    }

    public function consumeTranslation(string $translationId): void
    {
        $this->settle($translationId, 'consume', activity: 'translation');
    }

    public function releaseTranslation(string $translationId): void
    {
        $this->settle($translationId, 'release', activity: 'translation');
    }

    public function consumeDubbing(string $dubbingId): void
    {
        $this->settle($dubbingId, 'consume', activity: 'dubbing');
    }

    public function releaseDubbing(string $dubbingId): void
    {
        $this->settle($dubbingId, 'release', activity: 'dubbing');
    }

    public function consumeTool(string $id): void
    {
        $this->settle($id, 'consume', activity: 'transcript_tool');
    }

    public function releaseTool(string $id): void
    {
        $this->settle($id, 'release', activity: 'transcript_tool');
    }

    private function settle(string $transcriptionId, string $action, ?int $actualDurationMs = null, string $activity = 'transcription'): void
    {
        $this->repository->transaction(function () use ($transcriptionId, $action, $actualDurationMs, $activity): void {
            $charge = $activity !== 'transcription' ? $this->repository->charge($transcriptionId, lock: true, activity: $activity) : $this->repository->charge($transcriptionId, lock: true);
            if ($charge === null || $charge['status'] !== 'reserved') {
                return;
            }
            $wallet = $this->wallet((int) $charge['user_id']);
            $units = (int) $charge['credit_units'];
            if ($wallet->reserved < $units) {
                throw new BillingException('The reserved balance is inconsistent.');
            }
            $wallet->reserved -= $units;
            if ($action === 'release') {
                $wallet->available += $units;
            }
            $rate = $charge['rate'];
            $quantity = $actualDurationMs ?? (int) $charge['quantity'];
            $cost = isset($rate['provider_cost_micros'])
                ? CreditMath::prorate((int) $rate['provider_cost_micros'], $quantity, (int) $rate['unit_length'])
                : null;
            $this->repository->saveWallet($wallet);
            $this->repository->updateCharge($charge['id'], [
                'status' => $action === 'consume' ? 'consumed' : 'released',
                'provider_quantity' => $actualDurationMs,
                'provider_cost_micros' => $action === 'consume' ? $cost : null,
                'provider_cost_currency' => $action === 'consume' ? ($rate['provider_currency'] ?? null) : null,
            ]);
            $subjectKey = match ($activity) {
                'translation' => 'translation_id', 'dubbing' => 'dubbing_id', 'transcript_tool' => 'transcript_tool_id', default => 'transcription_id'
            };
            $this->repository->appendEntry($wallet, 'usage:'.$charge['quote_id'].':'.$action, $action, $units, [$subjectKey => $transcriptionId]);
        });
    }

    private function wallet(int $userId): Wallet
    {
        $wallet = $this->repository->lockWallet($userId);
        $free = $this->settings->freeCreditUnits();
        $key = 'welcome:'.$userId;
        if ($free > 0 && ! $this->repository->entryExists($key)) {
            $wallet->available += $free;
            $this->repository->saveWallet($wallet);
            $this->repository->appendEntry($wallet, $key, 'grant', $free);
        }

        return $wallet;
    }
}
