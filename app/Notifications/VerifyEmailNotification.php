<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 1. Email verification: sent at registration; the account cannot be used until the link is opened. */
final class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] public readonly string $url, public readonly string $name, public readonly int $hours)
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
            ->subject('Confirm your email for 10X Engage')
            ->greeting("Welcome, {$this->name}")
            ->line('Please confirm your email address to activate your 10X Engage account.')
            ->action('Verify email address', $this->url)
            ->line("This link works for {$this->hours} hours. If it expires, sign in and ask for a new one.")
            ->line('If you did not create an account, you can ignore this email.');
    }
}
