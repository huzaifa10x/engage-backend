<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;

/** Still no reply after the escalation time: tell the team lead, or reassign to the least busy teammate. */
final class ReplyEscalation extends TeamAlert
{
    public function run(?Conversation $conversation, ?Contact $contact, array $payload): string
    {
        if (! $this->stillWaiting($conversation, $payload)) {
            return 'cancelled:answered';
        }
        $s = FollowUps::settings(app(TenantContext::class)->tenantOrNull())['escalation'];

        if ($s['action'] === 'reassign') {
            $pick = $this->tools->pickAgent($conversation);
            if ($pick !== null && $pick->id !== $conversation->assigned_membership_id) {
                $from = $conversation->assigned_membership_id;
                $conversation->forceFill(['assigned_membership_id' => $pick->id])->save(); // broadcasts conversation.assigned
                $this->tools->notify($pick, 'assigned', "{$this->name($contact)} was reassigned to you: no reply for {$this->waited($conversation)}", null, "/inbox?c={$conversation->id}", email: true);
                if ($from !== null && ($old = TenantMembership::query()->find($from)) !== null) {
                    $this->tools->notify($old, 'reassigned', "{$this->name($contact)} was reassigned (no reply for {$this->waited($conversation)})", null, "/inbox?c={$conversation->id}");
                }

                return 'done:reassigned';
            }
        }

        $to = $this->leads($conversation, $s['notify_ids']);
        foreach ($to as $member) {
            $this->tools->notify($member, 'reply_escalated', "No reply to {$this->name($contact)} for {$this->waited($conversation)}", 'Escalated by your follow-up rule.', "/inbox?c={$conversation->id}", email: true);
        }

        return $to->isEmpty() ? 'skipped:nobody_to_tell' : 'done:escalated_'.$to->count();
    }
}
