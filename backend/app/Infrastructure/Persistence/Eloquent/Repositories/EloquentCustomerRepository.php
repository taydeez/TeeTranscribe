<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Admin\Auth\Services\AdminAccess;
use App\Domain\Admin\Customer\Contracts\CustomerRepositoryInterface;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentCustomerRepository implements CustomerRepositoryInterface
{
    public function find(int $id, bool $lock = false): array
    {
        $user = User::query()->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', AdminAccess::ROLES))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
        $actions = DB::table('customer_admin_actions as actions')->leftJoin('users as admins', 'admins.id', '=', 'actions.admin_id')
            ->where('actions.customer_id', $id)->orderByDesc('actions.created_at')->orderByDesc('actions.id')->limit(50)
            ->get(['actions.*', 'admins.name as admin_name'])->map(fn ($action): array => [
                'id' => $action->id, 'action' => $action->action, 'reason' => $action->reason,
                'adminName' => $action->admin_name, 'createdAt' => $action->created_at,
                'metadata' => json_decode($action->metadata ?? '{}', true, flags: JSON_THROW_ON_ERROR),
            ])->all();

        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'emailVerified' => $user->hasVerifiedEmail(), 'createdAt' => $user->created_at?->toIso8601String(),
            'status' => $user->isRestricted() ? $user->account_status : 'active',
            'suspendedUntil' => $user->suspended_until?->toIso8601String(),
            'restrictionReason' => $user->isRestricted() ? $user->restriction_reason : null,
            'signupIp' => $user->signup_ip, 'signupLocation' => $user->signup_location,
            'actions' => $actions,
        ];
    }

    public function setAccess(int $id, string $status, ?string $until, string $reason): void
    {
        $user = User::query()->findOrFail($id);
        $user->forceFill(['account_status' => $status, 'suspended_until' => $until,
            'restriction_reason' => $status === 'active' ? null : $reason])->save();
        if ($status !== 'active') {
            $user->tokens()->delete();
        }
    }

    public function operation(string $key): ?array
    {
        $record = DB::table('customer_admin_actions')->where('operation_key', $key)->first();

        return $record === null ? null : ['admin_id' => (int) $record->admin_id, 'reason' => $record->reason,
            'metadata' => json_decode($record->metadata, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function recordAction(int $id, int $adminId, string $action, string $reason, array $metadata, ?string $key = null): void
    {
        DB::table('customer_admin_actions')->insert([
            'id' => (string) Str::ulid(), 'customer_id' => $id, 'admin_id' => $adminId,
            'action' => $action, 'reason' => $reason, 'operation_key' => $key,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function paginate(int $page, int $perPage, string $search, string $sort): array
    {
        $query = User::query()
            ->select(['id', 'name', 'email', 'email_verified_at', 'created_at'])
            ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', AdminAccess::ROLES));

        if ($search !== '') {
            $query->where(fn (Builder $users) => $users
                ->whereLike('name', '%'.$search.'%')
                ->orWhereLike('email', '%'.$search.'%'));
        }

        [$column, $direction] = match ($sort) {
            'oldest' => ['created_at', 'asc'],
            'name_asc' => ['name', 'asc'],
            'name_desc' => ['name', 'desc'],
            default => ['created_at', 'desc'],
        };

        $customers = $query->orderBy($column, $direction)->orderBy('id', $direction)
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $customers->getCollection()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'emailVerified' => $user->email_verified_at !== null,
                'createdAt' => $user->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'currentPage' => $customers->currentPage(),
                'lastPage' => $customers->lastPage(),
                'perPage' => $customers->perPage(),
                'total' => $customers->total(),
            ],
        ];
    }
}
