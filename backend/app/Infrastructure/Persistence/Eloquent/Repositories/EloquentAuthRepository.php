<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Admin\Auth\Services\AdminAccess;
use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Domain\Auth\Entities\AuthenticatedUser;
use App\Infrastructure\Outbox\OutboxService;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EloquentAuthRepository implements AuthRepositoryInterface
{
    public function register(string $name, string $email, string $password): AuthenticatedUser
    {
        return DB::transaction(function () use ($name, $email, $password): AuthenticatedUser {
            $user = User::query()->create(compact('name', 'email', 'password'));
            $user->assignRole('user');
            $this->welcome($user);

            return $this->map($user);
        });
    }

    public function authenticate(string $email, string $password): ?AuthenticatedUser
    {
        $identifier = trim($email);
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $user = User::query()->whereNull('admin_deleted_at')->where('email', $identifier)->first()
                ?? User::query()->whereNull('admin_deleted_at')->whereHas('roles', fn ($roles) => $roles->whereIn('name', AdminAccess::ROLES))
                    ->whereRaw('LOWER(email) = ?', [mb_strtolower($identifier)])->first();
        } else {
            $user = User::query()->whereNull('admin_deleted_at')->where('username', mb_strtolower($identifier))->first();
        }

        return $user && Hash::check($password, $user->password) ? $this->map($user) : null;
    }

    public function findByEmail(string $email): ?AuthenticatedUser
    {
        $user = User::query()->whereNull('admin_deleted_at')->where('email', $email)->first();

        return $user ? $this->map($user) : null;
    }

    public function issueToken(int $userId, string $name): string
    {
        $user = User::query()->findOrFail($userId);
        if ($user->isRestricted()) {
            throw new AuthenticationException('Your account is blocked or suspended. Please contact support.');
        }
        if ($user->hasAnyRole(AdminAccess::ROLES)) {
            if ($name !== 'admin') {
                throw new AuthenticationException('Administrator email verification is required.');
            }

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user->createToken('admin', [AdminAccess::TOKEN_ABILITY], now()->addMinutes(AdminAccess::IDLE_MINUTES))->plainTextToken;
        }

        return $user->createToken($name)->plainTextToken;
    }

    public function upsertGoogle(string $googleId, string $name, string $email): AuthenticatedUser
    {
        return DB::transaction(function () use ($googleId, $name, $email): AuthenticatedUser {
            $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => Str::random(40)]);
            $newAccount = $user->wasRecentlyCreated;
            $user->forceFill(['google_id' => $googleId, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
            if (! $user->hasAnyRole(['user', ...AdminAccess::ROLES])) {
                $user->assignRole('user');
            }
            if ($newAccount) {
                $this->welcome($user);
            }

            return $this->map($user->refresh());
        });
    }

    private function welcome(User $user): void
    {
        app(OutboxService::class)->record('account:'.$user->id.':registered', 'AccountRegistered', (string) Str::ulid(), ['user_id' => $user->id]);
    }

    private function map(User $user): AuthenticatedUser
    {
        return new AuthenticatedUser($user->id, $user->name, $user->email, $user->getRoleNames()->all(), $user->hasVerifiedEmail());
    }
}
