<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

final class NewAccountNotification extends EmailVerificationNotification
{
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return true;
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Welcome to TeeTranscribe')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your TeeTranscribe account is ready. Turn your recordings into transcripts, translations, and dubbed videos.');
        if (! $notifiable->hasVerifiedEmail()) {
            return $mail->line('Verify your email address to start using your workspace.')
                ->action('Verify email address', $this->verificationUrl($notifiable))
                ->line('This verification link expires in 60 minutes.');
        }

        return $mail->action('Open your workspace', rtrim((string) config('app.frontend_url'), '/').'/dashboard');
    }
}
