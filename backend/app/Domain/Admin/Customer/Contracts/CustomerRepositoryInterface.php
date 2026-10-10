<?php

namespace App\Domain\Admin\Customer\Contracts;

interface CustomerRepositoryInterface
{
    public function paginate(int $page, int $perPage, string $search, string $sort): array;

    public function find(int $id, bool $lock = false): array;

    public function setAccess(int $id, string $status, ?string $until, string $reason): void;

    public function operation(string $key): ?array;

    public function recordAction(int $id, int $adminId, string $action, string $reason, array $metadata, ?string $key = null): void;
}
