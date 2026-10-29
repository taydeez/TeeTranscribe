<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Admin\Account\Contracts\AdminAccountRepositoryInterface;
use App\Domain\Admin\Auth\Services\AdminAccess;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class EloquentAdminAccountRepository implements AdminAccountRepositoryInterface
{
    public function paginate(int $page, string $search): array
    {
        $result = $this->query()->with('roles')->when($search !== '', fn (Builder $query) => $query
            ->where(fn (Builder $q) => $q->whereLike('name', '%'.$search.'%')->orWhereLike('email', '%'.$search.'%')->orWhereLike('username', '%'.$search.'%')))
            ->orderBy('name')->orderBy('id')->paginate(20, ['*'], 'page', $page);

        return ['data' => array_map($this->data(...), $result->items()), 'meta' => [
            'currentPage' => $result->currentPage(), 'lastPage' => $result->lastPage(), 'total' => $result->total(), 'perPage' => 20,
        ]];
    }

    public function create(int $actorId, array $data): array
    {
        return DB::transaction(function () use ($actorId, $data): array {
            if (User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])->exists()) {
                throw ValidationException::withMessages(['email' => 'This email address is already in use.']);
            }
            $roles = $this->assignableRoles($actorId, (int) $data['role_id']);
            $user = new User;
            $user->forceFill(['name' => trim($data['name']), 'username' => mb_strtolower($data['username']),
                'email' => mb_strtolower($data['email']), 'password' => $data['password'], 'must_change_password' => true])->save();
            $user->syncRoles($roles);

            return $this->data($user->load('roles'));
        }, 3);
    }

    public function updateRole(int $actorId, int $id, int $roleId): array
    {
        return DB::transaction(function () use ($actorId, $id, $roleId): array {
            $this->protectTarget($actorId, $id);
            $roles = $this->assignableRoles($actorId, $roleId);
            $user = $this->query()->lockForUpdate()->findOrFail($id);
            $user->syncRoles($roles);
            $user->tokens()->delete();

            return $this->data($user->load('roles'));
        }, 3);
    }

    public function delete(int $actorId, int $id): void
    {
        DB::transaction(function () use ($actorId, $id): void {
            $this->protectTarget($actorId, $id);
            $user = $this->query()->lockForUpdate()->findOrFail($id);
            $user->forceFill(['admin_deleted_at' => now(), 'account_status' => 'blocked'])->save();
            $user->tokens()->delete();
            DB::table('admin_login_codes')->where('user_id', $id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        }, 3);
    }

    public function changeInitialPassword(int $id, string $currentPassword, string $password): void
    {
        DB::transaction(function () use ($id, $currentPassword, $password): void {
            $user = $this->query()->lockForUpdate()->findOrFail($id);
            if (! $user->must_change_password || ! Hash::check($currentPassword, $user->password) || Hash::check($password, $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Check your current password and choose a different new password.']);
            }
            $user->forceFill(['password' => $password, 'must_change_password' => false, 'remember_token' => null])->save();
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        }, 3);
    }

    private function protectTarget(int $actorId, int $id): void
    {
        if ($actorId === $id) {
            throw ValidationException::withMessages(['account' => 'You cannot delete your own account or change your own role.']);
        }
        $supers = $this->query()->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'super_admin'))
            ->orderBy('id')->lockForUpdate()->get();
        $target = $this->query()->findOrFail($id);
        $actor = User::findOrFail($actorId);
        if ($target->hasRole('super_admin')) {
            if (! $actor->hasRole('super_admin')) {
                throw new AuthorizationException('Only a super admin may manage another super admin.');
            }
            if ($supers->count() <= 1) {
                throw ValidationException::withMessages(['account' => 'The last super admin cannot be removed.']);
            }
        }
        if (array_diff($target->getAllPermissions()->pluck('name')->all(), $actor->getAllPermissions()->pluck('name')->all()) !== []) {
            throw new AuthorizationException('You cannot manage an account with permissions you do not hold.');
        }
    }

    private function assignableRoles(int $actorId, int $roleId): array
    {
        $actor = User::findOrFail($actorId);
        $role = Role::where('guard_name', 'web')->findOrFail($roleId);
        if ($role->name === 'user') {
            throw ValidationException::withMessages(['role_id' => 'Select an administrator role.']);
        }
        if ($role->name === 'super_admin' && ! $actor->hasRole('super_admin')) {
            throw new AuthorizationException('Only a super admin may assign the super admin role.');
        }
        $roles = Role::where('guard_name', 'web')->where(fn ($query) => $query->where('name', 'admin')->orWhereKey($roleId))->with('permissions')->get();
        $permissions = $roles->flatMap(fn (Role $item) => $item->permissions->pluck('name'))->unique()->all();
        if (array_diff($permissions, $actor->getAllPermissions()->pluck('name')->all()) !== []) {
            throw new AuthorizationException('You may only grant permissions you hold.');
        }

        return $roles->all();
    }

    private function query(): Builder
    {
        return User::whereNull('admin_deleted_at')->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', AdminAccess::ROLES));
    }

    private function data(User $user): array
    {
        $selected = $user->roles->firstWhere('name', 'super_admin') ?? $user->roles->first(fn (Role $role) => $role->name !== 'admin') ?? $user->roles->first();

        return ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'email' => $user->email,
            'roleId' => $selected?->id, 'roles' => $user->roles->pluck('name')->all(),
            'mustChangePassword' => $user->must_change_password, 'createdAt' => $user->created_at?->toIso8601String()];
    }
}
