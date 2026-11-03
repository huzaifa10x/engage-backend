<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** For the 10X Engage team only (ENGAGE_ALERTS_EMAIL): something to look into, never sent to clients. */
final class InternalAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $context */
    public function __construct(public readonly string $subject, public readonly string $summary, public readonly array $context = [])
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
        $mail = (new MailMessage)->subject('[10X Engage] '.$this->subject)->greeting($this->subject)->line($this->summary);
        $mail->line('Details: '.json_encode($this->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $mail->action('Open Super Admin', rtrim((string) config('engage.admin_url', config('app.url')), '/').'/admin/numbers');
    }
}
