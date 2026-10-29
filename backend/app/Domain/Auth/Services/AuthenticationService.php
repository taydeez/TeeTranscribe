<?php

namespace App\Domain\Auth\Services;

use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use Illuminate\Auth\AuthenticationException;

class AuthenticationService
{
    public function __construct(private readonly AuthRepositoryInterface $repository) {}

    public function register(string $name, string $email, string $password): array
    {
        $user = $this->repository->register($name, $email, $password);

        return ['token' => $this->repository->issueToken($user->id, 'web'), 'user' => $user];
    }

    public function login(string $email, string $password): array
    {
        $user = $this->repository->authenticate($email, $password) ?? throw new AuthenticationException('Invalid credentials.');

        return ['user' => $user, 'is_admin' => $user->isAdmin(), 'token' => $user->isAdmin() ? null : $this->repository->issueToken($user->id, 'web')];
    }
}
