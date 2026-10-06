<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 1. Email verification: a one-time code sent at registration; the account cannot be used until it is entered. */
final class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] public readonly string $code, public readonly string $name, public readonly int $minutes)
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
            ->subject("{$this->code} is your 10X Engage verification code")
            ->greeting("Welcome, {$this->name}")
            ->line('Enter this code in 10X Engage to verify your email address and activate your account:')
            ->line("**{$this->code}**")
            ->line("The code expires in {$this->minutes} minutes and can be used once.")
            ->line('If you did not create an account, you can ignore this email. Never share this code with anyone.');
    }
}
