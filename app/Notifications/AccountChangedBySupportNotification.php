<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A security notice: 10X Engage support changed the sign-in email or the password of this account. */
final class AccountChangedBySupportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const PASSWORD = 'password';

    public const EMAIL = 'email';

    public function __construct(public readonly string $change, public readonly ?string $newEmail = null)
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
        $mail = new MailMessage;
        if ($this->change === self::PASSWORD) {
            $mail->subject('Your 10X Engage password was changed')->greeting('Your password was changed')
                ->line('10X Engage support set a new password on your account. You were signed out on every device; sign in with the new password.');
        } else {
            $mail->subject('Your 10X Engage sign-in email was changed')->greeting('Your sign-in email was changed')
                ->line("10X Engage support changed the email you sign in with to {$this->newEmail}.");
        }

        return $mail->action('Sign in', config('engage.frontend_url').'/login')
            ->line('If you did not ask for this, contact 10X Engage support straight away.');
    }
}
