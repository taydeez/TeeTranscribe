<?php

namespace App\Domain\Auth\Entities;

final readonly class AuthenticatedUser
{
    /** @param list<string> $roles */
    public function __construct(public int $id, public string $name, public string $email, public array $roles = []) {}
}
