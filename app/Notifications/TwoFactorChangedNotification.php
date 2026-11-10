<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A security notice: how this person signs in has changed. Sent for every change, by them or by support. */
final class TwoFactorChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const ENABLED = 'enabled';

    public const REPLACED = 'replaced';

    public const CODES_RENEWED = 'codes_renewed';

    public const DISABLED = 'disabled';

    public const RESET = 'reset';

    public const EMAIL_SIGN_IN = 'email_sign_in';

    public function __construct(public readonly string $change, public readonly ?string $ip = null)
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
        [$subject, $line] = match ($this->change) {
            self::ENABLED => ['Two-factor sign-in is on', 'Two-factor sign-in was turned on for your 10X Engage account. From now on you sign in with your password and a code from your authenticator app.'],
            self::REPLACED => ['Your authenticator app was replaced', 'The authenticator app for your 10X Engage account was replaced. Codes from the previous app no longer work, and your old recovery codes were replaced with new ones.'],
            self::CODES_RENEWED => ['New recovery codes were made', 'New recovery codes were made for your 10X Engage account. The previous recovery codes no longer work.'],
            self::EMAIL_SIGN_IN => ['You signed in without your authenticator', 'Someone signed in to your 10X Engage account with a code sent to this email address instead of the authenticator app. If you lost your phone, replace the authenticator in Settings › Two-factor sign-in.'],
            self::DISABLED => ['Two-factor sign-in was turned off', 'Two-factor sign-in was turned off for your 10X Engage account. You now sign in with your password and an emailed code.'],
            default => ['Two-factor sign-in was reset by support', 'At your request, 10X Engage support reset two-factor sign-in on your account. After you sign in, set up your authenticator app again.'],
        };

        $mail = (new MailMessage)->subject($subject)->greeting($subject)->line($line);
        if ($this->ip !== null && $this->change !== self::RESET) {
            $mail->line("Changed from IP address {$this->ip} at ".now()->format('j M Y, H:i').' (UTC).');
        }

        return $mail
            ->action('Review your sign-in', config('engage.frontend_url').'/settings?tab=security')
            ->line('If this was not you, reset your password straight away and contact support.');
    }
}
