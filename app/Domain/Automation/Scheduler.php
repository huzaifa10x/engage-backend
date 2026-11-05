<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\BusinessHours;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one scheduler for every timed action (WhatsApp Engagement Plan v4 §II.1, §IV.3): snooze
 * returns and the after-hours reply now; reply alerts, nudges and store messages build on it.
 * Features never run timers of their own.
 *
 * Each action has a kind (a handler), a due time, a priority (lower first: after-hours reply 10,
 * conversation nudge 20, store reminder 30), a de-duplication key and, for message-sending kinds,
 * a place in the per-contact frequency cap. Right before it runs, every check is made again, in
 * this fixed order — the first that applies decides:
 *   1. the contact opted out (and the kind needs consent)                    → skipped
 *   2. a person owns the chat (the kind stays out of a person's way)         → skipped
 *   3. the customer wrote since it was scheduled (the kind cancels on reply) → cancelled
 *   4. quiet hours → held until opening time (skipped if the handler says too late);
 *      the frequency cap → skipped
 *   5. otherwise the highest-priority due action for the contact runs; others due with it are cancelled.
 */
final class Scheduler
{
    /** Automated messages per contact per 7 days across all automations (number quality). */
    public const DEFAULT_WEEKLY_CAP = 3;

    /** @var array<string, class-string<ActionHandler>> */
    public const HANDLERS = [
        'snooze_return' => SnoozeReturn::class,
        'reply_alert' => ReplyAlert::class,
        'reply_escalation' => ReplyEscalation::class,
        'window_alert' => WindowAlert::class,
        'unassigned_assign' => UnassignedAssign::class,
        'nudge' => Nudge::class,
        'nudge_template' => TemplateNudge::class,
    ];

    /** A person wrote this recently: automations stay out of their way. */
    public const PERSON_RECENT_MINUTES = 60;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Queue an action (idempotent on the de-duplication key). Returns its id, or null when an action
     * with the same key already exists.
     *
     * @param  array<string, mixed>  $payload
     */
    public function schedule(string $kind, Carbon $dueAt, string $dedupeKey, ?Conversation $conversation = null, ?Contact $contact = null, array $payload = [], int $priority = 100, bool $sendsMessage = false): ?string
    {
        $tenant = $this->context->tenant();
        $id = (string) Str::uuid7();
        $inserted = DB::table('scheduled_actions')->insertOrIgnore([
            'id' => $id, 'tenant_id' => $tenant->id, 'kind' => $kind,
            'conversation_id' => $conversation?->id, 'contact_id' => $contact !== null ? $contact->id : $conversation?->contact_id,
            'due_at' => $dueAt->copy()->utc(), 'priority' => $priority, 'dedupe_key' => mb_substr($dedupeKey, 0, 191), 'status' => 'pending',
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'sends_message' => $sendsMessage,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $inserted > 0 ? $id : null;
    }

    /** Has an action with this key ever been recorded (pending or done)? */
    public function exists(string $dedupeKey): bool
    {
        return DB::table('scheduled_actions')->where('tenant_id', $this->context->tenant()->id)->where('dedupe_key', $dedupeKey)->exists();
    }

    /** Record an action that already happened right now (e.g. the after-hours reply started), for the ledger and the caps. */
    public function recordDone(string $kind, string $dedupeKey, Conversation $conversation, string $result, int $priority, bool $sendsMessage): bool
    {
        $id = $this->schedule($kind, now(), $dedupeKey, $conversation, null, [], $priority, $sendsMessage);
        if ($id === null) {
            return false;
        }
        DB::table('scheduled_actions')->where('id', $id)->update(['status' => 'done', 'result' => $result, 'decided_at' => now(), 'updated_at' => now()]);

        return true;
    }

    /** The customer wrote, the chat closed, an agent unsnoozed…: pending actions that stop on that are cancelled. */
    public function cancelForConversation(string $conversationId, string $reason, ?string $kind = null): int
    {
        $tenant = $this->context->tenantOrNull();
        if ($tenant === null) {
            return 0;
        }

        return DB::table('scheduled_actions')->where('tenant_id', $tenant->id)->where('conversation_id', $conversationId)->where('status', 'pending')
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->when($kind === null && $reason === 'customer_replied', fn ($q) => $q->whereRaw("coalesce((payload->>'cancel_on_reply')::boolean, false)"))
            ->update(['status' => 'cancelled', 'result' => $reason, 'decided_at' => now(), 'updated_at' => now()]);
    }

    /** Automated messages sent to a contact in the last 7 days. */
    public function sentThisWeek(string $contactId): int
    {
        return DB::table('scheduled_actions')->where('tenant_id', $this->context->tenant()->id)->where('contact_id', $contactId)
            ->where('status', 'done')->where('sends_message', true)->where('decided_at', '>=', now()->subDays(7))->count();
    }

    /** Runs every due action (every minute). Returns how many were decided. */
    public function runDue(int $limit = 500): int
    {
        $due = $this->context->bypass(fn () => DB::table('scheduled_actions')->where('status', 'pending')->where('due_at', '<=', now())
            ->orderBy('due_at')->limit($limit)->get(['id', 'tenant_id']));
        $decided = 0;
        foreach ($due->groupBy('tenant_id') as $tenantId => $rows) {
            $tenant = $this->context->bypass(fn () => Tenant::query()->find($tenantId));
            if ($tenant === null) {
                continue;
            }
            $this->context->run($tenant, function () use ($rows, &$decided) {
                foreach ($rows as $row) {
                    $decided += $this->runOne((string) $row->id) ? 1 : 0;
                }
            });
        }

        return $decided;
    }

    /** Decide one action now (in its workspace). */
    public function runOne(string $id): bool
    {
        return (bool) DB::transaction(function () use ($id) {
            $a = DB::table('scheduled_actions')->where('id', $id)->where('status', 'pending')->lockForUpdate()->first();
            if ($a === null) {
                return false;
            }
            $handlerClass = self::HANDLERS[$a->kind] ?? null;
            if ($handlerClass === null) {
                $this->decide($a->id, 'skipped', 'unknown_kind');

                return true;
            }
            /** @var ActionHandler $handler */
            $handler = app($handlerClass);
            $conversation = $a->conversation_id !== null ? Conversation::query()->find($a->conversation_id) : null;
            $contact = $a->contact_id !== null ? Contact::query()->find($a->contact_id) : null;
            $payload = (array) json_decode((string) $a->payload, true);

            try {
                $verdict = $this->checks($a, $handler, $conversation, $contact) ?? $handler->run($conversation, $contact, $payload);
            } catch (Throwable $e) {
                Log::warning('Scheduled action failed.', ['id' => $a->id, 'kind' => $a->kind, 'error' => $e->getMessage()]);
                $attempts = (int) $a->attempts + 1;
                DB::table('scheduled_actions')->where('id', $a->id)->update($attempts >= 3
                    ? ['status' => 'skipped', 'result' => 'failed', 'attempts' => $attempts, 'decided_at' => now(), 'updated_at' => now()]
                    : ['attempts' => $attempts, 'due_at' => now()->addMinutes(5 * $attempts), 'updated_at' => now()]);

                return true;
            }

            if (str_starts_with($verdict, 'hold:')) {
                DB::table('scheduled_actions')->where('id', $a->id)->update(['due_at' => Carbon::parse(substr($verdict, 5))->utc(), 'result' => 'held_quiet_hours', 'updated_at' => now()]);

                return true;
            }
            [$status, $result] = str_contains($verdict, ':') ? explode(':', $verdict, 2) : [$verdict, null];
            $this->decide($a->id, $status, $result);

            // At most one automated message per contact at a time: the others due now are cancelled.
            if ($status === 'done' && (bool) $a->sends_message && $a->contact_id !== null) {
                DB::table('scheduled_actions')->where('tenant_id', $a->tenant_id)->where('contact_id', $a->contact_id)->where('status', 'pending')
                    ->where('sends_message', true)->where('due_at', '<=', now())->where('id', '!=', $a->id)
                    ->update(['status' => 'cancelled', 'result' => 'lower_priority', 'decided_at' => now(), 'updated_at' => now()]);
            }

            return true;
        });
    }

    /** The fixed-order checks. Null = go ahead. */
    private function checks(object $a, ActionHandler $handler, ?Conversation $conversation, ?Contact $contact): ?string
    {
        if ($handler->needsConsent() && ($contact === null || $contact->isOptedOut())) {
            return 'skipped:opted_out';
        }
        if ($handler->staysOutOfPersonsWay() && $conversation !== null && $this->personOwns($conversation)) {
            return 'skipped:person_owns_chat';
        }
        if ($handler->cancelsOnReply() && $conversation !== null && $conversation->last_inbound_at !== null
            && $conversation->last_inbound_at->gt(Carbon::parse((string) $a->created_at))) {
            return 'cancelled:customer_replied';
        }
        if ($handler->respectsQuietHours() && $conversation !== null) {
            $tenant = $this->context->tenant();
            if (! BusinessHours::isOpen($tenant, $conversation->phone_number_id)) {
                $next = BusinessHours::nextOpen($tenant, $conversation->phone_number_id);
                if ($next === null || ! $handler->mayWaitUntil($conversation, $next)) {
                    return 'skipped:quiet_hours';
                }

                return 'hold:'.$next->toIso8601String();
            }
        }
        if ((bool) $a->sends_message && $contact !== null) {
            $cap = (int) FollowUps::settings($this->context->tenant())['weekly_cap'];
            if ($this->sentThisWeek($contact->id) >= $cap) {
                return 'skipped:frequency_cap';
            }
        }

        return null;
    }

    /** An agent took the chat over (the bot's handler is a person) and wrote within the last hour. */
    private function personOwns(Conversation $conversation): bool
    {
        return $conversation->getAttribute('handler') === 'human' && Message::query()->where('conversation_id', $conversation->id)
            ->where('direction', Message::OUTBOUND)->whereIn('origin', ['agent', 'api', 'app_echo'])
            ->where('created_at', '>=', now()->subMinutes(self::PERSON_RECENT_MINUTES))->exists();
    }

    private function decide(string $id, string $status, ?string $result): void
    {
        DB::table('scheduled_actions')->where('id', $id)->update(['status' => $status, 'result' => $result, 'decided_at' => now(), 'updated_at' => now()]);
    }
}
