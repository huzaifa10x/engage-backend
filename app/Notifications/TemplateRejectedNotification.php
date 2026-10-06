<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 6. Template rejected by Meta, with the reason and what to do next. */
final class TemplateRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $workspace, public readonly string $template, public readonly string $language, public readonly ?string $reason)
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
        $reason = $this->reason !== null ? ucfirst(strtolower(str_replace('_', ' ', $this->reason))) : null;

        return (new MailMessage)
            ->subject("WhatsApp template “{$this->template}” was rejected")
            ->line("Meta reviewed the template “{$this->template}” ({$this->language}) for {$this->workspace} and did not approve it.")
            ->line($reason !== null ? "Reason given by Meta: {$reason}." : 'Meta did not give a specific reason.')
            ->line('Common causes: promotional wording in a utility template, variables at the very start or end of the text, or missing sample values.')
            ->action('Review the template', config('engage.frontend_url').'/templates')
            ->line('Edit the wording and submit it again; a rejected template cannot be sent.');
    }
}
