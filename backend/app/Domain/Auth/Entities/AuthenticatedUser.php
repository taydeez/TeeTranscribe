<?php

namespace App\Domain\Auth\Entities;

use App\Domain\Admin\Auth\Services\AdminAccess;

final readonly class AuthenticatedUser
{
    /** @param list<string> $roles */
    public function __construct(public int $id, public string $name, public string $email, public array $roles = [], public bool $emailVerified = false) {}

    public function isAdmin(): bool
    {
        return AdminAccess::hasRole($this->roles);
    }
}
