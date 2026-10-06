<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 7. Payment receipt / VAT invoice for a paid subscription invoice. */
final class PaymentReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $workspace, public readonly ?string $number, public readonly string $subtotal, public readonly ?string $tax, public readonly string $total, public readonly ?string $pdfUrl, public readonly ?string $trn)
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
        $mail = (new MailMessage)
            ->subject('Payment receipt'.($this->number !== null ? " {$this->number}" : '').' from 10X Engage')
            ->greeting('Thank you for your payment')
            ->line("We received your payment for {$this->workspace}.")
            ->line("Amount: {$this->subtotal}")
            ->line($this->tax !== null ? "VAT: {$this->tax}" : 'VAT: not applicable')
            ->line("**Total paid: {$this->total}**");
        if ($this->trn !== null) {
            $mail->line("Your VAT TRN on this invoice: {$this->trn}");
        }

        return ($this->pdfUrl !== null ? $mail->action('Download the VAT invoice (PDF)', $this->pdfUrl) : $mail->action('View billing', config('engage.frontend_url').'/billing'))
            ->line('All your invoices are available on the Billing page.');
    }
}
