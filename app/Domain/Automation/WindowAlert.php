<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/** The 24 h window closes soon and nobody replied: urgent alert, because after it only a paid template reaches the customer. */
final class WindowAlert extends TeamAlert
{
    public function run(?Conversation $conversation, ?Contact $contact, array $payload): string
    {
        if (! $this->stillWaiting($conversation, $payload) || ! $conversation->isWindowOpen()) {
            return 'cancelled:answered';
        }
        $ids = FollowUps::settings(app(TenantContext::class)->tenantOrNull())['escalation']['notify_ids'];
        $to = $this->owners($conversation)->merge($this->leads($conversation, $ids))->unique('id');
        $left = $conversation->window_expires_at?->diffForHumans(now(), ['syntax' => Carbon::DIFF_ABSOLUTE]) ?? 'soon';
        foreach ($to as $member) {
            $this->tools->notify($member, 'window_closing', "Reply to {$this->name($contact)} within {$left}", 'After the 24-hour window closes, only a paid template can reach the customer.', "/inbox?c={$conversation->id}", email: true);
        }

        return $to->isEmpty() ? 'skipped:nobody_to_tell' : 'done:notified_'.$to->count();
    }
}
