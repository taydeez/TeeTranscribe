<?php

namespace App\Domain\Admin\Account\Contracts;

interface AdminAccountRepositoryInterface
{
    public function paginate(int $page, string $search): array;

    public function create(int $actorId, array $data): array;

    public function updateRole(int $actorId, int $id, int $roleId): array;

    public function delete(int $actorId, int $id): void;

    public function changeInitialPassword(int $id, string $currentPassword, string $password): void;
}
