<?php

namespace App\Domain\Admin\Role\Contracts;

interface AdminRoleRepositoryInterface
{
    public function paginate(int $page, int $perPage): array;

    public function find(int $id): array;

    public function create(string $name): array;

    public function update(int $id, string $name): array;

    public function syncPermissions(int $id, array $permissions): array;

    public function delete(int $id): void;

    public function permissions(): array;

    public function actorPermissions(int $userId): array;
}
