<?php

namespace App\Domain\Payment\Contracts;

use Closure;

interface PaymentRepositoryInterface
{
    public function transaction(Closure $operation): mixed;

    public function exclusive(string $key, Closure $operation): mixed;

    public function createPayment(array $data): array;

    public function paymentByClientKey(int $userId, string $key): ?array;

    public function payment(string $id, ?int $userId = null, bool $lock = false): ?array;

    public function paymentByReference(string $reference): ?array;

    public function updatePayment(string $id, array $data): array;

    public function pendingPayments(): iterable;

    public function expireUnconfirmedPayments(): int;

    public function enqueueInvoice(string $paymentId): void;
}
