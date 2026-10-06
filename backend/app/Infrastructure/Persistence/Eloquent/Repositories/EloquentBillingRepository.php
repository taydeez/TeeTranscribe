<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Entities\Wallet;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\BillingQuote;
use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\CreditWallet;
use App\Infrastructure\Persistence\Eloquent\Models\Payment;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Jobs\MeasureBillingQuote;
use Closure;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class EloquentBillingRepository implements BillingRepositoryInterface
{
    public function transaction(Closure $operation): mixed
    {
        return DB::transaction($operation, 3);
    }

    public function exclusive(string $key, Closure $operation): mixed
    {
        return Cache::lock('billing:'.$key, 900)->block(10, $operation);
    }

    public function lockWallet(int $userId): Wallet
    {
        CreditWallet::firstOrCreate(['user_id' => $userId], ['available_units' => 0, 'reserved_units' => 0]);
        $model = CreditWallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

        return new Wallet($model->id, $userId, $model->available_units, $model->reserved_units);
    }

    public function saveWallet(Wallet $wallet): void
    {
        if ($wallet->available < 0 || $wallet->reserved < 0) {
            throw new \LogicException('Credit balances cannot be negative.');
        }
        CreditWallet::whereKey($wallet->id)->update(['available_units' => $wallet->available, 'reserved_units' => $wallet->reserved]);
    }

    public function entryExists(string $key): bool
    {
        return CreditTransaction::where('event_key', $key)->exists();
    }

    public function appendEntry(Wallet $wallet, string $key, string $kind, int $units, array $metadata = []): void
    {
        CreditTransaction::create([
            'wallet_id' => $wallet->id, 'user_id' => $wallet->userId, 'event_key' => $key,
            'kind' => $kind, 'amount_units' => $units, 'available_after' => $wallet->available,
            'reserved_after' => $wallet->reserved, 'metadata' => $metadata,
        ]);
    }

    public function createQuote(array $data): array
    {
        return BillingQuote::create($data)->refresh()->toArray();
    }

    public function quoteByClientKey(int $userId, string $key): ?array
    {
        return BillingQuote::where('user_id', $userId)->where('client_key', $key)->first()?->toArray();
    }

    public function quote(string $id, ?int $userId = null, bool $lock = false): ?array
    {
        return BillingQuote::when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($lock, fn ($q) => $q->lockForUpdate())->find($id)?->toArray();
    }

    public function updateQuote(string $id, array $data): array
    {
        $model = BillingQuote::findOrFail($id);
        $model->update($data);

        return $model->refresh()->toArray();
    }

    public function charge(string $transcriptionId, bool $lock = false): ?array
    {
        return UsageCharge::where('transcription_id', $transcriptionId)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first()?->toArray();
    }

    public function createCharge(array $data): array
    {
        return UsageCharge::create($data)->refresh()->toArray();
    }

    public function updateCharge(string $id, array $data): void
    {
        UsageCharge::whereKey($id)->update($data);
    }

    public function history(int $userId, string $type, int $page, int $perPage): array
    {
        $model = match ($type) {
            'payments' => Payment::class, 'usage' => UsageCharge::class, default => CreditTransaction::class,
        };
        $result = $model::where('user_id', $userId)->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        $data = $result->getCollection()->map(function ($item) use ($type): array {
            $data = $item->toArray();
            if ($type === 'payments') {
                $data['invoice_ready'] = $item->status === 'paid' && $item->invoice_storage_path !== null;
            }

            return array_intersect_key($data, array_flip(match ($type) {
                'payments' => ['id', 'reference', 'package_name', 'credit_units', 'amount_minor', 'currency', 'status', 'paid_at', 'created_at', 'invoice_number', 'invoice_ready'],
                'usage' => ['id', 'transcription_id', 'activity', 'provider', 'model', 'quantity', 'credit_units', 'status', 'created_at'],
                default => ['id', 'kind', 'amount_units', 'available_after', 'reserved_after', 'metadata', 'created_at'],
            }));
        })->all();

        return ['data' => $data, 'meta' => [
            'current_page' => $result->currentPage(), 'last_page' => $result->lastPage(),
            'per_page' => $result->perPage(), 'total' => $result->total(),
        ]];
    }

    public function enqueue(string $key, string $type, string $id, array $payload): void
    {
        $event = app(OutboxService::class)->record($key, $type, $id, $payload);
        if ($type === 'BillingQuoteRequested' && $event->attempts === 0 && $event->published_at === null) {
            $event->increment('attempts');
            DB::afterCommit(function () use ($id, $event): void {
                $job = new MeasureBillingQuote($id, $event->id);
                try {
                    dispatch($job);
                } catch (\Throwable) {
                    try {
                        (new UniqueLock(app(CacheRepository::class)))->release($job);
                    } catch (\Throwable) {
                        // The outbox can recover after the cache connection returns and its lock expires.
                    }
                    Log::warning('Immediate media inspection dispatch deferred to the outbox.', ['quote_id' => $id]);
                }
            });
        }
    }
}
