<?php

namespace App\Domain\Admin\Log\Services;

use App\Domain\Admin\Log\Contracts\OperationalLogRepositoryInterface;

final readonly class OperationalLogService
{
    public function __construct(private OperationalLogRepositoryInterface $repository) {}

    public function paginate(array $filters): array
    {
        return $this->repository->paginate($filters);
    }

    public function find(string $id): ?array
    {
        return $this->repository->find($id);
    }
}
