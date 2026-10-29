<?php

namespace App\Domain\Admin\TwoFactor\Services;

use App\Domain\Admin\TwoFactor\Contracts\AdminCodeMailerInterface;
use App\Domain\Admin\TwoFactor\Contracts\AdminCodeRepositoryInterface;
use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use RuntimeException;

class AdminTwoFactorService
{
    public function __construct(private readonly AdminCodeRepositoryInterface $codes, private readonly AuthRepositoryInterface $users, private readonly AdminCodeMailerInterface $mailer) {}

    public function send(int $userId, string $email): void
    {
        $code = (string) random_int(100000, 999999);
        $this->codes->create($userId, hash('sha256', $code));
        $this->mailer->send($email, $code);
    }

    public function verify(string $email, string $code): string
    {
        $user = $this->users->findByEmail($email);
        if (! $user || ! $user->isAdmin() || ! $this->codes->consumeValid($user->id, hash('sha256', $code))) {
            throw new RuntimeException('Invalid or expired code.');
        }

        return $this->users->issueToken($user->id, 'admin');
    }
}
