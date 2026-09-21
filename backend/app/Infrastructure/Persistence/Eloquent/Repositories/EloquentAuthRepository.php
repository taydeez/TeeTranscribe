<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Domain\Auth\Entities\AuthenticatedUser;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EloquentAuthRepository implements AuthRepositoryInterface
{
    public function register(string $name, string $email, string $password): AuthenticatedUser
    {
        $user = User::query()->create(compact('name', 'email', 'password'));
        $user->assignRole('user');

        return $this->map($user);
    }

    public function authenticate(string $email, string $password): ?AuthenticatedUser
    {
        $user = User::query()->where('email', $email)->first();

        return $user && Hash::check($password, $user->password) ? $this->map($user) : null;
    }

    public function findByEmail(string $email): ?AuthenticatedUser
    {
        $user = User::query()->where('email', $email)->first();

        return $user ? $this->map($user) : null;
    }

    public function issueToken(int $userId, string $name): string
    {
        return User::query()->findOrFail($userId)->createToken($name)->plainTextToken;
    }

    public function upsertGoogle(string $googleId, string $name, string $email): AuthenticatedUser
    {
        $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => Str::random(40)]);
        $user->update(['google_id' => $googleId]);
        if (! $user->hasAnyRole(['user', 'admin'])) {
            $user->assignRole('user');
        }

        return $this->map($user->refresh());
    }

    private function map(User $user): AuthenticatedUser
    {
        return new AuthenticatedUser($user->id, $user->name, $user->email, $user->getRoleNames()->all());
    }
}
