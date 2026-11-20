<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Automation\FollowUps;
use App\Domain\Automation\Scheduler;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who each chat is waiting on (WhatsApp Engagement Plan v4 §II), set by the system, never by hand:
 *
 *  Needs reply          the customer still needs a person. A customer message starts it; a bot
 *                       greeting, an after-hours reply, a hand-off or a failed bot leave it as is.
 *                       Only a person's reply (agent, API, WhatsApp Business app) or closing ends it.
 *  Waiting on customer  the team (or the bot, or a campaign) wrote last and nobody needs a reply.
 *  Snoozed              snoozed_until in the future; comes back as Needs reply at that time.
 *  Closed               resolved by an agent, or by a bot end step that resolves the chat; a new
 *                       customer message reopens it as Needs reply.
 *
 * State, assignment and CRM stage are separate. "Overdue" is a timer on Needs reply, not a state.
 * Response figures measure people: each wait's first human reply and each resolution are stored in
 * business-hours seconds, per agent and number.
 */
final class ReplyState
{
    public const NEEDS_REPLY = 'needs_reply';

    public const WAITING = 'waiting';

    /**
     * What a newly stored message changes on its (locked) conversation.
     *
     * @return array<string, mixed>
     */
    public static function forMessage(Conversation $conversation, Message $message, Carbon $at, bool $isNewest): array
    {
        $tenant = app(TenantContext::class)->tenantOrNull();
        $state = $conversation->getAttribute('reply_state');

        if ($message->direction === Message::INBOUND && $message->origin === MessageOrigin::Customer) {
            if ($conversation->status === ConversationStatus::Closed || $state !== self::NEEDS_REPLY) {
                $content = (array) ($message->content ?? []);
                // The team-side follow-up rules for this wait (reply alert, escalation, window alert…).
                FollowUps::waitStarted($conversation, $at, $at->copy()->addHours((int) config('engage.messaging.window_hours', 24)));

                return [
                    'reply_state' => self::NEEDS_REPLY,
                    'needs_reply_since' => $at,
                    'reply_due_at' => ReplyPromise::dueAt($tenant, $conversation->phone_number_id, $at),
                    'after_hours' => ! BusinessHours::isOpen($tenant, $conversation->phone_number_id, $at),
                    'ad_lead' => isset($content['referral']),
                ] + ($conversation->status === ConversationStatus::Closed || $conversation->getAttribute('opened_at') === null ? ['opened_at' => $at] : []);
            }

            return [];
        }

        if ($message->direction !== Message::OUTBOUND || ! $isNewest) {
            return [];
        }

        // A person answered. Only a reply WhatsApp accepted counts: one sent from the WhatsApp Business
        // app already was; a reply from the inbox or the API ends the wait once Meta accepts it
        // (accepted()), never while it is still sending.
        if (self::isHuman($message)) {
            return $message->origin === MessageOrigin::AppEcho ? self::answeredBy($conversation, $message, $at) : [];
        }

        // The bot, a campaign or an automation wrote: it never counts as the team replying.
        return $state === self::NEEDS_REPLY ? [] : ['reply_state' => self::WAITING];
    }

    /** A person's message (inbox, API or the WhatsApp Business app), not the bot, a campaign or an automation. */
    public static function isHuman(Message $message): bool
    {
        return $message->direction === Message::OUTBOUND && in_array($message->origin, [MessageOrigin::Agent, MessageOrigin::Api, MessageOrigin::AppEcho], true);
    }

    /**
     * WhatsApp accepted a person's reply: if the chat was waiting on the team since before it was
     * written, the wait is over (the response time runs to when it was written).
     */
    public static function accepted(Message $message): void
    {
        if (! self::isHuman($message) || $message->origin === MessageOrigin::AppEcho) {
            return;
        }
        DB::transaction(function () use ($message): void {
            /** @var ?Conversation $conversation */
            $conversation = Conversation::query()->whereKey($message->conversation_id)->lockForUpdate()->first();
            if ($conversation === null || $conversation->status === ConversationStatus::Closed) {
                return;
            }
            $since = $conversation->getAttribute('needs_reply_since');
            $written = Carbon::instance($message->created_at ?? now());
            if ($conversation->getAttribute('reply_state') === self::NEEDS_REPLY && $since !== null) {
                if ($written->lt(Carbon::parse($since))) {
                    return; // written before this wait started: it answered an earlier message
                }
                $conversation->forceFill(self::answeredBy($conversation, $message, $written))->save();

                return;
            }
            // Already waiting on the customer (a follow-up message, or a chat the team started): the
            // nudge for when they go quiet counts from this message.
            FollowUps::waitingOnCustomer($conversation, $written, (string) $message->id);
            if ($conversation->getAttribute('reply_state') !== self::WAITING) {
                $conversation->forceFill(['reply_state' => self::WAITING])->save();
            }
        });
    }

    /**
     * A message failed. For a person's reply the state is worked out again from the messages (never
     * forced): the chat needs a reply again unless another accepted reply came after the customer's
     * last message. A failed campaign, automation or bot message never changes the state.
     */
    public static function failed(Message $message): void
    {
        if (! self::isHuman($message)) {
            return;
        }
        DB::transaction(function () use ($message): void {
            /** @var ?Conversation $conversation */
            $conversation = Conversation::query()->whereKey($message->conversation_id)->lockForUpdate()->first();
            if ($conversation === null || $conversation->status === ConversationStatus::Closed) {
                return;
            }
            $updates = self::recalculate($conversation);
            if ($updates !== []) {
                $conversation->forceFill($updates)->save();
            }
        });
    }

    /**
     * Who the chat waits on, from its messages: the customer's messages after the last person's reply
     * that WhatsApp accepted. Starts the wait again (with its alerts) when it had been ended by a reply
     * that later failed.
     *
     * @return array<string, mixed>
     */
    public static function recalculate(Conversation $conversation): array
    {
        $accepted = [MessageStatus::Accepted->value, MessageStatus::Sent->value, MessageStatus::Delivered->value, MessageStatus::Read->value];
        $time = 'COALESCE(meta_timestamp, created_at)';
        $lastReply = Message::query()->where('conversation_id', $conversation->id)->where('direction', Message::OUTBOUND)
            ->whereIn('origin', [MessageOrigin::Agent->value, MessageOrigin::Api->value, MessageOrigin::AppEcho->value])
            ->whereIn('status', $accepted)->max(DB::raw($time));
        $firstUnanswered = Message::query()->where('conversation_id', $conversation->id)->where('direction', Message::INBOUND)
            ->where('origin', MessageOrigin::Customer->value)
            ->when($lastReply !== null, fn ($q) => $q->where(DB::raw($time), '>', $lastReply))->min(DB::raw($time));

        if ($firstUnanswered === null) {
            return $conversation->getAttribute('reply_state') === self::NEEDS_REPLY ? [] : ['reply_state' => self::WAITING];
        }
        $since = Carbon::parse($firstUnanswered);
        if ($conversation->getAttribute('reply_state') === self::NEEDS_REPLY && $conversation->getAttribute('needs_reply_since') !== null) {
            return [];
        }

        $tenant = app(TenantContext::class)->tenantOrNull();
        // The response time recorded for the failed reply no longer stands.
        DB::table('inbox_response_times')->where('conversation_id', $conversation->id)->where('kind', 'first_response')->where('started_at', $since)->delete();
        $windowEnds = $conversation->window_expires_at ?? $since->copy()->addHours((int) config('engage.messaging.window_hours', 24));
        FollowUps::waitStarted($conversation, $since, Carbon::instance($windowEnds));

        return [
            'reply_state' => self::NEEDS_REPLY,
            'needs_reply_since' => $since,
            'reply_due_at' => ReplyPromise::dueAt($tenant, $conversation->phone_number_id, $since),
            'after_hours' => ! BusinessHours::isOpen($tenant, $conversation->phone_number_id, $since),
        ];
    }

    /** @return array<string, mixed> */
    private static function answeredBy(Conversation $conversation, Message $message, Carbon $at): array
    {
        if ($conversation->getAttribute('reply_state') === self::NEEDS_REPLY && $conversation->getAttribute('needs_reply_since') !== null) {
            self::record($conversation, 'first_response', Carbon::parse($conversation->getAttribute('needs_reply_since')), $at, $message->getAttribute('sent_by_membership_id'));
        }
        FollowUps::answered($conversation);
        // Now waiting on the customer: the nudge for when they go quiet.
        FollowUps::waitingOnCustomer($conversation, $at, (string) $message->id);

        return ['reply_state' => self::WAITING, 'needs_reply_since' => null, 'reply_due_at' => null, 'after_hours' => false, 'ad_lead' => false];
    }

    /**
     * A chat was closed (agent, bot end step, rule): the wait ends without a reply; resolution time recorded.
     *
     * @return array<string, mixed>
     */
    public static function closed(Conversation $conversation, ?string $membershipId = null, string $reason = 'resolved'): array
    {
        // Each closure is kept with who, when and why (the Resolved tab, 3.5).
        Resolutions::closed($conversation, $membershipId, $reason);
        $opened = $conversation->getAttribute('opened_at');
        if ($opened !== null) {
            self::record($conversation, 'resolution', Carbon::parse($opened), now(), $membershipId);
        }
        app(Scheduler::class)->cancelForConversation((string) $conversation->id, 'closed');

        return ['reply_state' => null, 'needs_reply_since' => null, 'reply_due_at' => null, 'after_hours' => false, 'ad_lead' => false];
    }

    /**
     * A chat was reopened by hand (or flagged and reopened): who it waits on is worked out from its
     * messages, never set. When the customer's message is still unanswered it needs a reply, waiting
     * since that message; the alerts for this wait start now, so reopening never fires a burst of
     * overdue alerts.
     *
     * @return array<string, mixed>
     */
    public static function reopened(Conversation $conversation): array
    {
        return self::fromMessages($conversation) + ['opened_at' => now()];
    }

    /**
     * A snooze ended: the chat is back in the queue, and its reply state is worked out from its messages
     * (Needs reply only while the customer's last message is unanswered). Alerts start now.
     *
     * @return array<string, mixed>
     */
    public static function snoozeEnded(Conversation $conversation): array
    {
        return ['snoozed_until' => null] + self::fromMessages($conversation)
            + ($conversation->getAttribute('opened_at') === null ? ['opened_at' => now()] : []);
    }

    /**
     * The reply state from the human-reply rule alone: the customer's first message after the last
     * person's reply WhatsApp accepted. No side effects.
     *
     * @return array<string, mixed>
     */
    public static function fromMessages(Conversation $conversation): array
    {
        $accepted = [MessageStatus::Accepted->value, MessageStatus::Sent->value, MessageStatus::Delivered->value, MessageStatus::Read->value];
        $time = 'COALESCE(meta_timestamp, created_at)';
        $lastReply = Message::query()->where('conversation_id', $conversation->id)->where('direction', Message::OUTBOUND)
            ->whereIn('origin', [MessageOrigin::Agent->value, MessageOrigin::Api->value, MessageOrigin::AppEcho->value])
            ->whereIn('status', $accepted)->max(DB::raw($time));
        $firstUnanswered = Message::query()->where('conversation_id', $conversation->id)->where('direction', Message::INBOUND)
            ->where('origin', MessageOrigin::Customer->value)
            ->when($lastReply !== null, fn ($q) => $q->where(DB::raw($time), '>', $lastReply))->min(DB::raw($time));
        if ($firstUnanswered === null) {
            return ['reply_state' => self::WAITING, 'needs_reply_since' => null, 'reply_due_at' => null, 'after_hours' => false];
        }
        $since = Carbon::parse($firstUnanswered);
        $tenant = app(TenantContext::class)->tenantOrNull();

        return [
            'reply_state' => self::NEEDS_REPLY,
            'needs_reply_since' => $since,
            'reply_due_at' => ReplyPromise::dueAt($tenant, $conversation->phone_number_id, $since),
            'after_hours' => ! BusinessHours::isOpen($tenant, $conversation->phone_number_id, $since),
        ];
    }

    /** After reopening or a snooze ending: a chat that needs a reply gets its alerts, counted from now. */
    public static function alertsFromNow(Conversation $conversation): void
    {
        if ($conversation->getAttribute('reply_state') === self::NEEDS_REPLY) {
            FollowUps::waitStarted($conversation, Carbon::parse($conversation->getAttribute('needs_reply_since')), $conversation->window_expires_at, now());
        }
    }

    /** The state shown in the inbox. */
    public static function shown(Conversation $conversation): string
    {
        if ($conversation->status === ConversationStatus::Closed) {
            return 'closed';
        }
        $snoozed = $conversation->getAttribute('snoozed_until');
        if ($snoozed !== null && Carbon::parse($snoozed)->isFuture()) {
            return 'snoozed';
        }

        return $conversation->getAttribute('reply_state') === self::NEEDS_REPLY ? self::NEEDS_REPLY : self::WAITING;
    }

    private static function record(Conversation $conversation, string $kind, Carbon $from, Carbon $to, ?string $membershipId): void
    {
        $tenant = app(TenantContext::class)->tenantOrNull();
        if ($tenant === null) {
            return;
        }
        DB::table('inbox_response_times')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'phone_number_id' => $conversation->phone_number_id,
            'membership_id' => $membershipId,
            'kind' => $kind,
            'started_at' => $from,
            'ended_at' => $to,
            'business_seconds' => BusinessHours::seconds($tenant, $conversation->phone_number_id, $from, $to),
            'after_hours' => (bool) $conversation->getAttribute('after_hours'),
            'ad_lead' => (bool) $conversation->getAttribute('ad_lead'),
            'created_at' => now(),
        ]);
    }
}
