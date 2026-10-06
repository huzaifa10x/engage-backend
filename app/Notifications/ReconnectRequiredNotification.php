<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 5. Reconnect required: a number stopped working and needs action from the customer. */
final class ReconnectRequiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $workspace, public readonly string $number, public readonly string $reason)
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
            ->subject("Action needed: reconnect WhatsApp number {$this->number}")
            ->greeting('Your WhatsApp number is disconnected')
            ->line("{$this->number} is no longer connected to {$this->workspace}. Until it is reconnected, messages cannot be sent or received through 10X Engage.")
            ->line("Reason: {$this->reason}")
            ->action('Reconnect the number', config('engage.frontend_url').'/channels')
            ->line('Your contacts, conversations and templates are kept and will be available again once the number is reconnected.');
    }
}
