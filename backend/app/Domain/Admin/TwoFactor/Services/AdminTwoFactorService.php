<?php

namespace App\Domain\Admin\TwoFactor\Services;

use App\Domain\Admin\TwoFactor\Contracts\AdminCodeRepositoryInterface;
use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class AdminTwoFactorService
{
    public function __construct(private readonly AdminCodeRepositoryInterface $codes, private readonly AuthRepositoryInterface $users) {}

    public function send(int $userId, string $email): void
    {
        $code = (string) random_int(100000, 999999);
        $this->codes->create($userId, hash('sha256', $code));
        Mail::raw("Your TeeTranscribe administrator login code is {$code}.", fn ($mail) => $mail->to($email)->subject('Administrator login code'));
    }

    public function verify(string $email, string $code): string
    {
        $user = $this->users->findByEmail($email);
        if (! $user || ! in_array('admin', $user->roles, true) || ! $this->codes->consumeValid($user->id, hash('sha256', $code))) {
            throw new RuntimeException('Invalid or expired code.');
        }

        return $this->users->issueToken($user->id, 'admin');
    }
}
