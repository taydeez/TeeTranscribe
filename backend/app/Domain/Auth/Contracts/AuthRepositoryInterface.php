<?php

namespace App\Domain\Auth\Contracts;

use App\Domain\Auth\Entities\AuthenticatedUser;

interface AuthRepositoryInterface
{
    public function register(string $name, string $email, string $password): AuthenticatedUser;

    public function authenticate(string $email, string $password): ?AuthenticatedUser;

    public function findByEmail(string $email): ?AuthenticatedUser;

    public function issueToken(int $userId, string $name): string;

    public function upsertGoogle(string $googleId, string $name, string $email): AuthenticatedUser;
}
