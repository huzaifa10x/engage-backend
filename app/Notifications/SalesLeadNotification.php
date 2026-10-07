<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Billing\Models\SalesLead;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the sales inbox that someone asked for a demo or sent the contact form on the website. */
final class SalesLeadNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SalesLead $lead)
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
        $lead = $this->lead;
        $mail = (new MailMessage)
            ->subject("New {$lead->topic} request: {$lead->name}".($lead->company ? " ({$lead->company})" : ''))
            ->replyTo($lead->email, $lead->name)
            ->greeting('New request from the website')
            ->line("**Name:** {$lead->name}")
            ->line("**Email:** {$lead->email}");
        foreach (['company' => 'Company', 'phone' => 'Phone', 'team_size' => 'Numbers / clients', 'source' => 'Sent from'] as $field => $label) {
            if ($lead->{$field}) {
                $mail->line("**{$label}:** {$lead->{$field}}");
            }
        }
        if ($lead->message) {
            $mail->line('**Message:**')->line($lead->message);
        }

        return $mail->line('Reply to this email to answer them directly.');
    }
}
