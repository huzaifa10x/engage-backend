<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The one-time code for email-based two-factor authentication on the Super Admin panel. */
final class AdminTwoFactorCodeNotification extends Notification
{
    public function __construct(public readonly string $code, private readonly string $purpose, private readonly int $minutes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->code} is your 10X Engage admin code")
            ->greeting('Your verification code')
            ->line("Use this code to {$this->purpose} to the 10X Engage admin panel:")
            ->line("**{$this->code}**")
            ->line("It expires in {$this->minutes} minutes and can be used once.")
            ->line('If you did not request this, someone knows your password. Change it and tell your platform owner.');
    }
}
