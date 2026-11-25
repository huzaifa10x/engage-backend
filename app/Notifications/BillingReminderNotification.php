<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Contracts\HasEmailCategory;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Batch 6, avoiding failed payments: the card renewals are charged to expires soon (30 and 7 days
 * before), or an annual plan renews in 7 days.
 */
final class BillingReminderNotification extends Notification implements HasEmailCategory, ShouldQueue
{
    use Queueable;

    public const CARD_EXPIRING = 'card_expiring';

    public const ANNUAL_RENEWAL = 'annual_renewal';

    /** @param  self::CARD_EXPIRING|self::ANNUAL_RENEWAL  $kind */
    public function __construct(public readonly string $kind, public readonly string $workspace, public readonly string $date, public readonly ?string $card = null, public readonly ?string $amount = null)
    {
        $this->onQueue(QueueName::Notifications->value);
        $this->afterCommit();
    }

    public function emailCategory(): string
    {
        return 'billing';
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $billing = config('engage.frontend_url').'/billing';

        if ($this->kind === self::CARD_EXPIRING) {
            return (new MailMessage)
                ->subject('Your card for 10X Engage expires soon')
                ->greeting('Your card expires soon')
                ->line("The card used for {$this->workspace}".($this->card !== null ? " ({$this->card})" : '')." expires at the end of {$this->date}.")
                ->line('Update it now so your next renewal goes through. If a renewal fails, paid features pause straight away.')
                ->action('Update card', $billing);
        }

        return (new MailMessage)
            ->subject('Your 10X Engage annual plan renews in 7 days')
            ->greeting('Your annual plan renews soon')
            ->line("The annual plan for {$this->workspace} renews on {$this->date}".($this->amount !== null ? " for {$this->amount} (plus VAT where it applies)." : '.'))
            ->line('Check that your card is up to date, or change or cancel the plan before then.')
            ->action('Review billing', $billing);
    }
}
