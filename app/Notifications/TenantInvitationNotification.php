<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TenantInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $tenantName,
        #[\SensitiveParameter] public readonly string $token,
        public readonly string $roleName,
    ) {
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
        $url = config('engage.frontend_url').'/invitations/'.$this->token;

        return (new MailMessage)
            ->subject("You've been invited to {$this->tenantName} on 10X Engage")
            ->line("You've been invited to join {$this->tenantName} as {$this->roleName}.")
            ->action('Accept invitation', $url)
            ->line('This invitation expires in '.((int) config('engage.invitations.ttl_hours') / 24).' days.');
    }
}
