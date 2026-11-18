<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells our team that an Owner scheduled, cancelled or completed deleting their workspace. */
final class WorkspaceDeletionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param 'requested'|'cancelled'|'deleted'|string $what */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $workspace,
        public readonly string $what,
        public readonly ?string $scheduledFor,
        public readonly ?string $byEmail,
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
        $mail = (new MailMessage)->subject(match ($this->what) {
            'requested' => "Workspace deletion requested: {$this->workspace}",
            'cancelled' => "Workspace deletion cancelled: {$this->workspace}",
            default => "Workspace deleted: {$this->workspace}",
        })
            ->line("**Workspace:** {$this->workspace}")
            ->line("**Workspace ID:** {$this->tenantId}");
        if ($this->byEmail !== null) {
            $mail->line("**By:** {$this->byEmail}");
        }

        return match ($this->what) {
            'requested' => $mail->line('**Deleted on:** '.($this->scheduledFor ?? 'after the waiting period'))
                ->line('Its API keys were revoked and webhooks paused. The Owner can cancel until then; a Super Admin can delete it sooner from the admin panel.'),
            'cancelled' => $mail->line('The Owner cancelled the deletion. The workspace stays.'),
            default => $mail->line('The waiting period ended: the workspace was closed and its data is being erased.'),
        };
    }
}
