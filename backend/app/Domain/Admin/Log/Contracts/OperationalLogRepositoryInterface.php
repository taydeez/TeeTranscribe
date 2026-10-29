<?php

namespace App\Domain\Admin\Log\Contracts;

interface OperationalLogRepositoryInterface
{
    public function paginate(array $filters): array;

    public function find(string $id): ?array;
}
