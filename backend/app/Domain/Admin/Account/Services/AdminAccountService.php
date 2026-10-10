<?php

namespace App\Domain\Admin\Account\Services;

use App\Domain\Admin\Account\Contracts\AdminAccountRepositoryInterface;

final class AdminAccountService
{
    public function __construct(private AdminAccountRepositoryInterface $accounts) {}

    public function paginate(int $page, string $search): array
    {
        return $this->accounts->paginate($page, trim($search));
    }

    public function create(int $actorId, array $data): array
    {
        return $this->accounts->create($actorId, $data);
    }

    public function updateRole(int $actorId, int $id, int $roleId): array
    {
        return $this->accounts->updateRole($actorId, $id, $roleId);
    }

    public function delete(int $actorId, int $id): void
    {
        $this->accounts->delete($actorId, $id);
    }

    public function changeInitialPassword(int $id, string $currentPassword, string $password): void
    {
        $this->accounts->changeInitialPassword($id, $currentPassword, $password);
    }
}
