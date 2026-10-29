<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

final class PasswordResetNotification extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(string $token)
    {
        parent::__construct($token);
        $this->onConnection('redis')->onQueue('default')->afterCommit();
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    protected function resetUrl($notifiable): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/auth/reset-password?'.http_build_query([
            'token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
