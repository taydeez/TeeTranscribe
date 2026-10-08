<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\URL;

class EmailVerificationNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct()
    {
        $this->onConnection('redis')->onQueue('default')->afterCommit();
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! $notifiable->hasVerifiedEmail();
    }

    protected function verificationUrl($notifiable): string
    {
        $signed = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification()),
        ], absolute: false);
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return rtrim((string) config('app.frontend_url'), '/').'/auth/verify-email?'.http_build_query([
            'id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification()),
            'expires' => $query['expires'], 'signature' => $query['signature'],
        ]);
    }
}
