<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Messaging\Services\ReplyState;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Support\Carbon;

/** A snooze ended: the chat comes back as Needs reply and its assignee is told. */
final class SnoozeReturn extends ActionHandler
{
    public function __construct(private readonly InboxTools $tools) {}

    public function run(?Conversation $conversation, ?Contact $contact, array $payload): string
    {
        $until = isset($payload['until']) ? Carbon::parse((string) $payload['until']) : null;
        $current = $conversation?->getAttribute('snoozed_until');
        // Unsnoozed, re-snoozed to another time, or the customer already brought it back.
        if ($conversation === null || $current === null || $until === null || ! Carbon::parse($current)->equalTo($until)) {
            return 'cancelled:snooze_changed';
        }
        $conversation->forceFill(ReplyState::snoozeEnded($conversation))->save();
        $member = $conversation->assigned_membership_id !== null ? TenantMembership::query()->find($conversation->assigned_membership_id) : null;
        if ($member !== null) {
            $this->tools->notify($member, 'snooze_ended', 'A snoozed conversation is back in your inbox', $contact?->displayName(), "/inbox?c={$conversation->id}");
        }

        return 'done';
    }
}
