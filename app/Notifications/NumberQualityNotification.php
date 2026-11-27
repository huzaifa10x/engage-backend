<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Contracts\HasEmailCategory;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Meta lowered a number's standing: quality rating Yellow or Red, flagged, or a lower messaging limit. */
final class NumberQualityNotification extends Notification implements HasEmailCategory, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $workspace, public readonly string $title, public readonly string $body)
    {
        $this->onQueue(QueueName::Notifications->value);
        $this->afterCommit();
    }

    public function emailCategory(): string
    {
        return 'whatsapp_numbers';
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
            ->subject($this->title)
            ->greeting($this->title)
            ->line($this->body)
            ->action('Open Channels', config('engage.frontend_url').'/channels')
            ->line("You get this email because you are an Owner of {$this->workspace} on engageX.");
    }
}
