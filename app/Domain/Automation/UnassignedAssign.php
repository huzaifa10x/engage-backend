<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;

/** Unassigned for N minutes: assign it by round-robin (least busy teammate who can reply on the number). */
final class UnassignedAssign extends TeamAlert
{
    public function run(?Conversation $conversation, ?Contact $contact, array $payload): string
    {
        if (! $this->stillWaiting($conversation, $payload) || $conversation->assigned_membership_id !== null) {
            return 'cancelled:assigned_or_answered';
        }
        $pick = $this->tools->pickAgent($conversation);
        if ($pick === null) {
            return 'skipped:nobody_available';
        }
        if (Conversation::query()->whereKey($conversation->id)->whereNull('assigned_membership_id')->exists()) {
            $conversation->forceFill(['assigned_membership_id' => $pick->id])->save();
            $this->tools->notify($pick, 'assigned', 'A waiting conversation was assigned to you', $contact?->displayName(), "/inbox?c={$conversation->id}", email: true);
        }

        return 'done:assigned';
    }
}
