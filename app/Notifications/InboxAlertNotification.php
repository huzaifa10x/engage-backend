<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Contracts\HasEmailCategory;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email copy of an inbox alert: a conversation was assigned to you, or you were mentioned in a note. */
final class InboxAlertNotification extends Notification implements HasEmailCategory, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly ?string $body, public readonly string $url, public readonly string $category = 'inbox_alerts')
    {
        $this->onQueue(QueueName::Notifications->value);
        $this->afterCommit();
    }

    public function emailCategory(): string
    {
        // Emails queued before categories existed have no category property: count them as alerts.
        return isset($this->category) ? $this->category : 'inbox_alerts'; // @phpstan-ignore isset.property
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->line($this->title.'.');
        if ($this->body !== null && $this->body !== '') {
            $mail->line("“{$this->body}”");
        }

        return $mail->action('Open the conversation', $this->url)->line('You get this email because of your role in a 10X Engage workspace.');
    }
}
