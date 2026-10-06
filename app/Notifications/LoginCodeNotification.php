<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The one-time code that completes a sign-in. Sent immediately (not queued): the user is waiting for it. */
final class LoginCodeNotification extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $code, public readonly int $minutes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->code} is your 10X Engage sign-in code")
            ->greeting('Your sign-in code')
            ->line('Enter this code to finish signing in to 10X Engage:')
            ->line("**{$this->code}**")
            ->line("It expires in {$this->minutes} minutes and can be used once.")
            ->line('If you did not try to sign in, someone has your password. Reset it now and do not share this code.');
    }
}
