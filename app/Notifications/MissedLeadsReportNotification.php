<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The weekly missed-leads report for an Owner (3.3, A3). */
final class MissedLeadsReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $report */
    public function __construct(public readonly string $workspace, public readonly array $report)
    {
        $this->onQueue(QueueName::Notifications->value);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->report;
        $n = (int) $r['enquiries'];
        $pct = fn (int $x) => $n > 0 ? ' ('.round($x / $n * 100).'%)' : '';
        $aed = fn ($v) => 'AED '.number_format((float) $v);
        $mail = (new MailMessage)
            ->subject("{$this->workspace}: {$r['over']} of {$n} enquiries waited over {$r['limit_minutes']} minutes last week")
            ->greeting("Missed leads, {$r['week']['from']} to {$r['week']['to']}")
            ->line("Enquiries in: {$n}")
            ->line("Answered within {$r['limit_minutes']} business minutes: {$r['in_time']}".$pct((int) $r['in_time']))
            ->line("Waited over the limit: {$r['over']}".$pct((int) $r['over']));
        if ($r['aed_at_stake'] !== null) {
            $mail->line('AED at stake: '.$aed($r['aed_at_stake']).' (waited over × average booking value)');
        }
        $mail->line('AED protected: '.$aed($r['aed_protected']).' (verified payments only)');
        foreach (array_slice($r['waited_over'], 0, 10) as $row) {
            $mail->line("• {$row['customer']}: waited ".intdiv((int) $row['waited_seconds'], 60).' min'.($row['answered'] ? '' : ', no reply yet').($row['owner'] ? " · {$row['owner']}" : ''));
        }

        return $mail->action('Open the report', config('engage.frontend_url').'/growth/leads?tab=missed');
    }
}
