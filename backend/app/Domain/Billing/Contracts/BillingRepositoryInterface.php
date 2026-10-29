<?php

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\Entities\Wallet;
use Closure;

interface BillingRepositoryInterface
{
    public function transaction(Closure $operation): mixed;

    public function lockWallet(int $userId): Wallet;

    public function saveWallet(Wallet $wallet): void;

    public function entryExists(string $key): bool;

    public function appendEntry(Wallet $wallet, string $key, string $kind, int $units, array $metadata = []): void;

    public function createQuote(array $data): array;

    public function quoteByClientKey(int $userId, string $key): ?array;

    public function quote(string $id, ?int $userId = null, bool $lock = false): ?array;

    public function updateQuote(string $id, array $data): array;

    public function charge(string $transcriptionId, bool $lock = false, string $activity = 'transcription'): ?array;

    public function createCharge(array $data): array;

    public function updateCharge(string $id, array $data): void;

    public function history(int $userId, string $type, int $page, int $perPage): array;

    public function enqueue(string $key, string $type, string $id, array $payload): void;

    public function exclusive(string $key, Closure $operation): mixed;
}
