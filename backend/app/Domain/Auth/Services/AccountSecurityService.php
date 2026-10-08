<?php

namespace App\Domain\Auth\Services;

use App\Domain\Auth\Contracts\AccountSecurityGatewayInterface;

final readonly class AccountSecurityService
{
    public function __construct(private AccountSecurityGatewayInterface $gateway) {}

    public function updateName(int $userId, string $name): void
    {
        $this->gateway->updateName($userId, $name);
    }

    public function changePassword(int $userId, string $currentPassword, string $password): bool
    {
        return $this->gateway->changePassword($userId, $currentPassword, $password);
    }

    public function requestPasswordReset(string $email): void
    {
        $this->gateway->requestPasswordReset($email);
    }

    public function resetPassword(string $email, string $token, string $password): bool
    {
        return $this->gateway->resetPassword($email, $token, $password);
    }

    public function sendVerification(int $userId): void
    {
        $this->gateway->sendVerification($userId);
    }

    public function verifyEmail(int $userId, string $hash): bool
    {
        return $this->gateway->verifyEmail($userId, $hash);
    }
}
