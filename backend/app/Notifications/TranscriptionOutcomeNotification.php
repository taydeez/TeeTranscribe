<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TranscriptionOutcomeNotification extends Notification
{
    public function __construct(public readonly string $transcriptionName, public readonly bool $completed, public readonly string $folderUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->completed ? 'Transcription complete' : 'Transcription failed')
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->completed
                ? 'Your transcription "'.$this->transcriptionName.'" is complete. Your TXT and PDF files are ready.'
                : 'Your transcription "'.$this->transcriptionName.'" could not be completed. Please try again.')
            ->action($this->completed ? 'Open transcription folder' : 'View transcriptions', $this->folderUrl);
    }
}
