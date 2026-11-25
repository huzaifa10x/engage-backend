<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Contracts\HasEmailCategory;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 8. Payment failed (first dunning notice): the renewal charge did not go through. */
final class PaymentFailedNotification extends Notification implements HasEmailCategory, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $workspace, public readonly ?string $number, public readonly string $total, public readonly ?string $retryOn)
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
        return (new MailMessage)
            ->error()
            ->subject('Payment failed for your 10X Engage subscription')
            ->greeting('We could not charge your card')
            ->line("The payment of {$this->total} for {$this->workspace}".($this->number !== null ? " (invoice {$this->number})" : '').' did not go through.')
            ->line('Paid features (chatbots, automations, reply alerts, campaigns and extra team seats) are paused until it is paid. Sending and receiving messages keep working.')
            ->line($this->retryOn !== null ? "Stripe will try your card again on {$this->retryOn}. To restore everything now, update your card: the invoice is paid with it straight away." : 'Update your card to pay the invoice: everything comes back within seconds.')
            ->action('Update card and pay', config('engage.frontend_url').'/billing')
            ->line('Your chats, contacts and settings are kept.');
    }
}
