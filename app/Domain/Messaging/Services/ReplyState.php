<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Automation\Scheduler;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Enums\MessageOrigin;
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

                return [
                    'reply_state' => self::NEEDS_REPLY,
                    'needs_reply_since' => $at,
                    'after_hours' => ! BusinessHours::isOpen($tenant, $conversation->phone_number_id, $at),
                    'ad_lead' => isset($content['referral']),
                ] + ($conversation->status === ConversationStatus::Closed || $conversation->getAttribute('opened_at') === null ? ['opened_at' => $at] : []);
            }

            return [];
        }

        if ($message->direction !== Message::OUTBOUND || ! $isNewest) {
            return [];
        }

        // A person answered: the wait is over. Its business-hours response time is recorded.
        if (in_array($message->origin, [MessageOrigin::Agent, MessageOrigin::Api, MessageOrigin::AppEcho], true)) {
            if ($state === self::NEEDS_REPLY && $conversation->getAttribute('needs_reply_since') !== null) {
                self::record($conversation, 'first_response', Carbon::parse($conversation->getAttribute('needs_reply_since')), $at, $message->getAttribute('sent_by_membership_id'));
            }

            return ['reply_state' => self::WAITING, 'needs_reply_since' => null, 'after_hours' => false, 'ad_lead' => false];
        }

        // The bot, a campaign or an automation wrote: it never counts as the team replying.
        return $state === self::NEEDS_REPLY ? [] : ['reply_state' => self::WAITING];
    }

    /**
     * A chat was closed (agent, bot end step, rule): the wait ends without a reply; resolution time recorded.
     *
     * @return array<string, mixed>
     */
    public static function closed(Conversation $conversation, ?string $membershipId = null): array
    {
        $opened = $conversation->getAttribute('opened_at');
        if ($opened !== null) {
            self::record($conversation, 'resolution', Carbon::parse($opened), now(), $membershipId);
        }
        app(Scheduler::class)->cancelForConversation((string) $conversation->id, 'closed');

        return ['reply_state' => null, 'needs_reply_since' => null, 'after_hours' => false, 'ad_lead' => false];
    }

    /**
     * A chat was reopened by hand: nobody is waiting yet.
     *
     * @return array<string, mixed>
     */
    public static function reopened(): array
    {
        return ['reply_state' => self::WAITING, 'needs_reply_since' => null, 'opened_at' => now()];
    }

    /**
     * A snooze ended: the chat comes back as Needs reply.
     *
     * @return array<string, mixed>
     */
    public static function snoozeEnded(Conversation $conversation): array
    {
        return ['snoozed_until' => null, 'reply_state' => self::NEEDS_REPLY, 'needs_reply_since' => now()]
            + ($conversation->getAttribute('opened_at') === null ? ['opened_at' => now()] : []);
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
