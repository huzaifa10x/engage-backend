<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 4. WhatsApp number connected: confirmation to the workspace owners. */
final class NumberConnectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $workspace, public readonly string $number, public readonly ?string $displayName)
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
            ->subject("WhatsApp number {$this->number} is connected")
            ->greeting('Your number is live')
            ->line(($this->displayName ? "{$this->displayName} ({$this->number})" : $this->number)." is now connected to {$this->workspace} on 10X Engage.")
            ->line('You can receive and reply to customer messages in the Team Inbox, and send approved templates.')
            ->action('Open the inbox', config('engage.frontend_url').'/inbox')
            ->line('WhatsApp conversation fees are billed by Meta directly to your business account.');
    }
}
