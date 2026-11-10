<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Database\Eloquent\Builder;

/**
 * One definition of the inbox counts, used by the Inbox tabs and the Dashboard, so the two always
 * agree: open, not snoozed, visible to this person (agents: their own chats), optionally one number.
 *
 *  needs_reply             the Needs reply tab (ReplyState: a customer is waiting on a person)
 *  needs_reply_unassigned  …of which nobody is assigned
 *  needs_reply_mine        …assigned to me
 *  after_hours             …arrived outside opening hours
 *  unassigned              every open unassigned chat (the Unassigned tab)
 *  older_no_reply          open chats whose last message is the customer's but that are not in Needs
 *                          reply: imported history (WhatsApp Business app) and chats from before reply
 *                          tracking started. Shown apart, never mixed into Needs reply.
 */
final class InboxCounts
{
    public function __construct(private readonly NumberAccess $access) {}

    /** @return Builder<Conversation> */
    public function base(TenantMembership $membership, ?string $phoneNumberId): Builder
    {
        $q = Conversation::query()->whereNotNull('last_message_at')->where('status', ConversationStatus::Open->value)
            ->where(fn ($w) => $w->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->when($phoneNumberId, fn ($q, string $id) => $q->where('phone_number_id', $id));

        return $this->access->scopeChats($q, $membership);
    }

    /** @return array{needs_reply: int, needs_reply_unassigned: int, needs_reply_mine: int, after_hours: int, unassigned: int, older_no_reply: int} */
    public function counts(TenantMembership $membership, ?string $phoneNumberId): array
    {
        $needs = fn () => $this->base($membership, $phoneNumberId)->where('reply_state', ReplyState::NEEDS_REPLY);

        return [
            'needs_reply' => $needs()->count(),
            'needs_reply_unassigned' => $needs()->whereNull('assigned_membership_id')->count(),
            'needs_reply_mine' => $needs()->where('assigned_membership_id', $membership->id)->count(),
            'after_hours' => $needs()->where('after_hours', true)->count(),
            'unassigned' => $this->base($membership, $phoneNumberId)->whereNull('assigned_membership_id')->count(),
            'older_no_reply' => $this->base($membership, $phoneNumberId)->where('last_message_direction', Message::INBOUND)
                ->where(fn ($w) => $w->whereNull('reply_state')->orWhere('reply_state', '!=', ReplyState::NEEDS_REPLY))->count(),
        ];
    }
}
