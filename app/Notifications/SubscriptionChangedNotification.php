<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Contracts\HasEmailCategory;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Plan emails engageX sends itself (Batch 6). Stripe sends the rest: renewal reminders, expiring
 * cards and failed card or bank-debit payments. The receipt for every paid invoice, renewals
 * included, is PaymentReceiptNotification.
 *
 *  started    a paid plan began (upgrade from Free or a trial)
 *  changed    the plan or billing period changed now (upgrade, Monthly → Annual)
 *  scheduled  a downgrade or Annual → Monthly switch waits for the end of the period
 *  cancelled  the plan will not renew; it runs until the end of the period already paid for
 *  resumed    a cancelled plan will renew again
 *  paused     a renewal is unpaid: paid features are paused (Stripe emails how to pay)
 *  restored   the unpaid invoice was paid: paid features are back
 *  ended      the paid plan ended: the workspace is on Free
 */
final class SubscriptionChangedNotification extends Notification implements HasEmailCategory, ShouldQueue
{
    use Queueable;

    public const KINDS = ['started', 'changed', 'scheduled', 'cancelled', 'resumed', 'paused', 'restored', 'ended'];

    /**
     * @param  value-of<self::KINDS>  $kind
     * @param  string  $plan  the plan the email is about (the new plan for started / changed / scheduled)
     * @param  ?string  $from  the plan before a change, e.g. "Growth (Monthly)"
     * @param  ?string  $date  renewal, change or end date, already formatted
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $workspace,
        public readonly string $plan,
        public readonly ?string $from = null,
        public readonly ?string $date = null,
    ) {
        $this->onQueue(QueueName::Notifications->value);
        $this->afterCommit();
    }

    public function emailCategory(): string
    {
        return 'billing';
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = new MailMessage;
        $billing = config('engage.frontend_url').'/billing';
        $on = $this->date !== null ? " on {$this->date}" : '';

        return match ($this->kind) {
            'started' => $mail->subject("Welcome to 10X Engage {$this->plan}")
                ->greeting("You're on {$this->plan}")
                ->line("{$this->workspace} is now on {$this->plan}. Chatbots, automations, reply alerts, campaigns and your extra team seats are switched on.")
                ->line($this->date !== null ? "Your plan renews automatically on {$this->date}." : 'Your plan renews automatically.')
                ->line('Your receipt and VAT invoice are in a separate email.')
                ->action('View billing', $billing),
            'changed' => $mail->subject("Your plan is now {$this->plan}")
                ->greeting('Your plan has changed')
                ->line("{$this->workspace} has moved".($this->from !== null ? " from {$this->from}" : '')." to {$this->plan}.")
                ->line('The difference for the rest of this period was charged now; your receipt is in a separate email.'.($this->date !== null ? " The plan renews on {$this->date}." : ''))
                ->action('View billing', $billing),
            'scheduled' => $mail->subject("Your plan changes to {$this->plan}{$on}")
                ->greeting('Your plan change is scheduled')
                ->line("{$this->workspace} stays on ".($this->from ?? 'its current plan')." until the end of the period you have paid for, then moves to {$this->plan}{$on}.")
                ->line('Nothing is refunded and nothing more is charged until then.')
                ->action('View billing', $billing),
            'cancelled' => $mail->subject('Your 10X Engage plan will not renew')
                ->greeting('Your plan is cancelled')
                ->line("{$this->workspace} keeps {$this->plan} until the end of the period you have paid for".($this->date !== null ? ", {$this->date}" : '').'. It will not renew, and nothing more is charged.')
                ->line('After that the workspace moves to the Free plan. Your chats, contacts and settings stay, and you can upgrade again at any time.')
                ->action('View billing', $billing),
            'resumed' => $mail->subject('Your 10X Engage plan will renew')
                ->greeting('Your plan will renew')
                ->line("{$this->workspace} stays on {$this->plan}. It renews automatically".($this->date !== null ? " on {$this->date}" : '').'.')
                ->action('View billing', $billing),
            'paused' => $mail->error()->subject('Paid features are paused on 10X Engage')
                ->greeting('Paid features are paused')
                ->line("The renewal payment for {$this->workspace} did not go through, so chatbots, automations, reply alerts, campaigns and extra team seats are paused. Sending and receiving messages keep working.")
                ->line('Stripe has emailed you about the payment. Update your card and the unpaid invoice is paid straight away: everything comes back within seconds.')
                ->action('Update card and pay', $billing),
            'restored' => $mail->subject('Paid features are back on 10X Engage')
                ->greeting('Everything is back on')
                ->line("Thank you: the payment for {$this->workspace} went through and {$this->plan} is fully active again.")
                ->action('View billing', $billing),
            default => $mail->subject('Your workspace is now on the Free plan')
                ->greeting('Your paid plan has ended')
                ->line("{$this->plan} has ended for {$this->workspace}, and the workspace is now on Free. Sending and receiving messages keep working; chats, contacts and settings are kept.")
                ->line('Upgrade at any time to switch the paid features back on.')
                ->action('Upgrade', $billing),
        };
    }
}
