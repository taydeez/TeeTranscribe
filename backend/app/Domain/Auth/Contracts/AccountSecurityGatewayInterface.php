<?php

namespace App\Domain\Auth\Contracts;

interface AccountSecurityGatewayInterface
{
    public function updateName(int $userId, string $name): void;

    public function changePassword(int $userId, string $currentPassword, string $password): bool;

    public function requestPasswordReset(string $email): void;

    public function resetPassword(string $email, string $token, string $password): bool;

    public function sendVerification(int $userId): void;

    public function verifyEmail(int $userId, string $hash): bool;
}
