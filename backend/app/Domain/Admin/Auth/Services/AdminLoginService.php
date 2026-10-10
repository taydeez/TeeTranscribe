<?php

namespace App\Domain\Admin\Auth\Services;

use App\Domain\Admin\TwoFactor\Services\AdminTwoFactorService;
use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use Illuminate\Auth\AuthenticationException;

final class AdminLoginService
{
    public function __construct(private readonly AuthRepositoryInterface $users, private readonly AdminTwoFactorService $twoFactor) {}

    public function login(string $email, string $password): string
    {
        $user = $this->users->authenticate($email, $password);
        if (! $user || ! $user->isAdmin()) {
            throw new AuthenticationException('Invalid credentials.');
        }
        $this->twoFactor->send($user->id, $user->email);

        return $user->email;
    }
}
