<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A customer's webhook endpoint kept failing and was switched off. Sent to the workspace owners. */
final class WebhookEndpointDisabledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $workspace, public readonly string $url, public readonly int $failures)
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
            ->subject("A webhook endpoint in {$this->workspace} was switched off")
            ->greeting('A webhook endpoint stopped responding')
            ->line("We could not deliver the last {$this->failures} events to:")
            ->line("**{$this->url}**")
            ->line('To protect your systems and ours, the endpoint has been switched off. No further events are sent to it.')
            ->action('Open Developer settings', config('engage.frontend_url').'/developer?tab=webhooks')
            ->line('Fix the address or your server, then switch the endpoint back on. Events from while it was off are not replayed automatically; you can resend recent ones from the delivery log.');
    }
}
