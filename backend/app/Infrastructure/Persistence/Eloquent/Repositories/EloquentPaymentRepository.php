<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Payment\Contracts\PaymentRepositoryInterface;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\Payment;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentPaymentRepository implements PaymentRepositoryInterface
{
    public function enqueueInvoice(string $paymentId): void
    {
        app(OutboxService::class)->record('payment:'.$paymentId.':confirmed', 'PaymentConfirmed', $paymentId, ['payment_id' => $paymentId]);
    }

    public function transaction(Closure $operation): mixed
    {
        return DB::transaction($operation, 3);
    }

    public function exclusive(string $key, Closure $operation): mixed
    {
        return Cache::lock('billing:'.$key, 900)->block(10, $operation);
    }

    public function createPayment(array $data): array
    {
        $gateway = $data['gateway'];
        $secret = (string) config('payment.'.$gateway.'.secret');
        $testMode = $gateway === 'paystack' ? ! str_starts_with($secret, 'sk_live_') : str_starts_with($secret, 'FLWSECK_TEST');

        return Payment::create([
            ...$data, 'reference' => 'credits_'.Str::ulid(),
            'environment' => $testMode ? 'test' : 'live',
        ])->refresh()->toArray();
    }

    public function paymentByClientKey(int $userId, string $key): ?array
    {
        return Payment::where('user_id', $userId)->where('client_key', $key)->first()?->toArray();
    }

    public function payment(string $id, ?int $userId = null, bool $lock = false): ?array
    {
        return Payment::when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($lock, fn ($q) => $q->lockForUpdate())->find($id)?->toArray();
    }

    public function paymentByReference(string $reference): ?array
    {
        return Payment::where('reference', $reference)->first()?->toArray();
    }

    public function updatePayment(string $id, array $data): array
    {
        $model = Payment::findOrFail($id);
        $model->update($data);

        return $model->refresh()->toArray();
    }

    public function pendingPayments(): iterable
    {
        foreach (Payment::whereIn('status', ['pending', 'initializing', 'expired'])
            ->where('created_at', '>=', now()->subDays(3))->lazyById(100) as $model) {
            yield $model->toArray();
        }
    }

    public function expireUnconfirmedPayments(): int
    {
        return Payment::whereIn('status', ['quoted', 'pending', 'initializing'])
            ->where('expires_at', '<=', now())->update(['status' => 'expired']);
    }
}
