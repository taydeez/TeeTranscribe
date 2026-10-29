<?php

namespace App\Domain\Admin\Role\Services;

use App\Domain\Admin\Role\Contracts\AdminRoleRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class AdminRoleService
{
    private const PROTECTED_ROLES = ['user', 'admin', 'super_admin'];

    public function __construct(private readonly AdminRoleRepositoryInterface $roles) {}

    public function paginate(int $page, int $perPage): array
    {
        return $this->roles->paginate($page, $perPage);
    }

    public function find(int $id): array
    {
        return $this->roles->find($id);
    }

    public function create(string $name): array
    {
        $this->assertCustomName($name);

        return $this->roles->create($name);
    }

    public function update(int $id, string $name): array
    {
        $this->assertMutable($id);
        $this->assertCustomName($name);

        return $this->roles->update($id, $name);
    }

    public function syncPermissions(int $id, array $permissions, int $actorId): array
    {
        $role = $this->roles->find($id);
        if (in_array($role['name'], ['user', 'super_admin'], true)) {
            throw ValidationException::withMessages(['role' => 'Permissions for this system role cannot be changed.']);
        }
        if (array_diff($permissions, $this->roles->actorPermissions($actorId)) !== []) {
            throw new AuthorizationException('You may only grant permissions you hold.');
        }

        return $this->roles->syncPermissions($id, $permissions);
    }

    public function delete(int $id): void
    {
        $this->assertMutable($id);
        $this->roles->delete($id);
    }

    public function permissions(): array
    {
        return $this->roles->permissions();
    }

    private function assertMutable(int $id): void
    {
        if (in_array($this->roles->find($id)['name'], self::PROTECTED_ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'This system role cannot be renamed or deleted.']);
        }
    }

    private function assertCustomName(string $name): void
    {
        if (in_array(mb_strtolower(trim($name)), self::PROTECTED_ROLES, true)) {
            throw ValidationException::withMessages(['name' => 'This role name is reserved.']);
        }
    }
}
