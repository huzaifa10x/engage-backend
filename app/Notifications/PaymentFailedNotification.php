<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 8. Payment failed (first dunning notice): the renewal charge did not go through. */
final class PaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $workspace, public readonly ?string $number, public readonly string $total, public readonly ?string $retryOn)
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
            ->error()
            ->subject('Payment failed for your 10X Engage subscription')
            ->greeting('We could not charge your card')
            ->line("The payment of {$this->total} for {$this->workspace}".($this->number !== null ? " (invoice {$this->number})" : '').' did not go through.')
            ->line($this->retryOn !== null ? "We will try again on {$this->retryOn}. To avoid any interruption, update your card or pay the invoice now." : 'Please update your card or pay the invoice now to keep your plan.')
            ->action('Update payment method', config('engage.frontend_url').'/billing')
            ->line('If the invoice stays unpaid, the workspace moves to the Free plan and paid features are switched off. Your data is kept.');
    }
}
