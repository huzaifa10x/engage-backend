<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 2. Password reset: a single-use link to choose a new password. */
final class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] public readonly string $url, public readonly int $minutes)
    {
        $this->onQueue(QueueName::Notifications->value);
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your 10X Engage password')
            ->line('We received a request to reset the password for your 10X Engage account.')
            ->action('Choose a new password', $this->url)
            ->line("This link works for {$this->minutes} minutes and can be used once.")
            ->line('If you did not ask for this, no action is needed: your password stays the same.');
    }
}
