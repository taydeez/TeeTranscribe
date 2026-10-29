<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Admin\Role\Contracts\AdminRoleRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class EloquentAdminRoleRepository implements AdminRoleRepositoryInterface
{
    public function paginate(int $page, int $perPage): array
    {
        $results = Role::query()->where('guard_name', 'web')->with('permissions')->orderBy('name')->paginate($perPage, ['*'], 'page', $page);

        return ['data' => array_map($this->data(...), $results->items()),
            'meta' => ['currentPage' => $results->currentPage(), 'lastPage' => $results->lastPage(), 'perPage' => $results->perPage(), 'total' => $results->total()]];
    }

    public function find(int $id): array
    {
        return $this->data($this->model($id));
    }

    public function create(string $name): array
    {
        return DB::transaction(function () use ($name): array {
            $this->assertUnique($name);

            return $this->data(Role::create(['name' => $name, 'guard_name' => 'web']));
        });
    }

    public function update(int $id, string $name): array
    {
        return DB::transaction(function () use ($id, $name): array {
            $record = $this->model($id);
            $this->assertUnique($name, $id);
            $record->update(['name' => $name]);

            return $this->data($record);
        });
    }

    public function syncPermissions(int $id, array $permissions): array
    {
        return DB::transaction(function () use ($id, $permissions): array {
            $models = Permission::query()->where('guard_name', 'web')->whereIn('name', $permissions)->get();
            if ($models->count() !== count($permissions)) {
                throw ValidationException::withMessages(['permissions' => 'One or more permissions do not exist.']);
            }
            $record = $this->model($id);
            $record->syncPermissions($models);

            return $this->data($record->load('permissions'));
        });
    }

    public function delete(int $id): void
    {
        $record = $this->model($id);
        if ($record->users()->exists()) {
            throw ValidationException::withMessages(['role' => 'Remove this role from its users before deleting it.']);
        }
        $record->delete();
    }

    public function permissions(): array
    {
        return Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all();
    }

    public function actorPermissions(int $userId): array
    {
        return User::query()->findOrFail($userId)->getAllPermissions()->pluck('name')->all();
    }

    private function model(int $id): Role
    {
        return Role::query()->where('guard_name', 'web')->with('permissions')->findOrFail($id);
    }

    private function assertUnique(string $name, ?int $except = null): void
    {
        if (Role::query()->where('guard_name', 'web')->where('name', $name)->when($except !== null, fn ($query) => $query->whereKeyNot($except))->exists()) {
            throw ValidationException::withMessages(['name' => 'This role name is already in use.']);
        }
    }

    private function data(Role $role): array
    {
        return ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->permissions->pluck('name')->sort()->values()->all()];
    }
}
