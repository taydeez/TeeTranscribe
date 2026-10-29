<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CreditMovementNotification extends Notification
{
    public function __construct(public readonly string $kind, public readonly int $units,
        public readonly int $availableUnits, public readonly int $reservedUnits, public readonly string $activity) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reserved = $this->kind === 'reserve';
        $amount = number_format($this->units / 100, 2);

        return (new MailMessage)->subject($reserved ? 'Credits reserved' : 'Credits returned')
            ->greeting('Hello '.$notifiable->name.',')
            ->line($reserved
                ? $amount.' credits have been reserved for your '.$this->activity.'. They will be used when processing succeeds or returned if it fails.'
                : $amount.' credits reserved for your '.$this->activity.' have been returned to your available balance.')
            ->line('Available balance: '.number_format($this->availableUnits / 100, 2).' credits.')
            ->line('Reserved balance: '.number_format($this->reservedUnits / 100, 2).' credits.')
            ->action('View credit history', rtrim((string) config('app.frontend_url'), '/').'/dashboard/billing');
    }
}
