<?php

namespace App\Domain\Admin\Auth\Services;

final class AdminAccess
{
    public const ROLES = ['admin', 'super_admin'];

    public const IDLE_MINUTES = 5;

    public const TOKEN_ABILITY = 'admin:verified';

    public static function hasRole(array $roles): bool
    {
        return array_intersect(self::ROLES, $roles) !== [];
    }
}
