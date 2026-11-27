<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditLogger;
use App\Domain\Automation\FollowUps;
use App\Domain\Automation\Scheduler;
use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Feedback\FeedbackRequests;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Events\TeammateTyping;
use App\Domain\Messaging\Jobs\SendReadReceipt;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\InboxTeam;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\InboxCounts;
use App\Domain\Messaging\Services\InboxHealth;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Messaging\Services\ReplyPromise;
use App\Domain\Messaging\Services\ReplyState;
use App\Domain\Messaging\Services\Resolutions;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ContactResource;
use App\Http\Resources\Api\V1\ConversationResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Team inbox threads. Every query is limited to the numbers this member may use. */
final class ConversationController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly NumberAccess $access) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'phone_number_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::enum(ConversationStatus::class)],
            'assigned' => ['nullable', Rule::in(['me', 'unassigned', 'others', 'any'])],
            'team' => ['nullable', 'uuid'],
            // One teammate's chats (Agents › a person, on the phone).
            'assignee' => ['nullable', 'uuid'],
            // Chatbot views: handled by the bot now, waiting on the customer, or how the bot ended.
            'view' => ['nullable', Rule::in(['bot', 'waiting', 'abandoned', 'completed', 'handed_off'])],
            'unread' => ['nullable', 'boolean'],
            'snoozed' => ['nullable', 'boolean'],
            // Inbox 3.5: All shows snoozed chats too (with their wake-up time).
            'include_snoozed' => ['nullable', 'boolean'],
            // Who it is waiting on (Engagement Plan §II); after_hours = "Arrived after hours".
            'state' => ['nullable', Rule::in(['needs_reply', 'waiting'])],
            'after_hours' => ['nullable', 'boolean'],
            // Inbox 3.5 filters: the WhatsApp window, an open follow-up on the contact, a tag.
            'window' => ['nullable', Rule::in(['open', 'closed', 'closing'])],
            'followup' => ['nullable', 'boolean'],
            'tag' => ['nullable', 'string', 'max:40'],
            // Inbox 3.5 sort. Without it the list keeps its cursor pages, newest first.
            'sort' => ['nullable', Rule::in(['priority', 'newest', 'waiting'])],
            'cursor' => ['nullable', 'string', 'max:500'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $membership = $this->membership();
        $searching = trim((string) ($data['q'] ?? '')) !== '';

        $query = Conversation::query()->with(['contact.stage', 'phoneNumber'])->whereNotNull('last_message_at');
        $this->access->scopeChats($query, $membership); // agents: only the chats assigned to them

        $query
            ->when($data['phone_number_id'] ?? null, fn ($q, $id) => $q->where('phone_number_id', $id))
            ->when(! $searching ? ($data['status'] ?? null) : null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['team'] ?? null, fn ($q, $team) => $q->where('assigned_team_id', $team))
            ->when(! $searching ? ($data['assignee'] ?? null) : null, fn ($q, $id) => $q->where('assigned_membership_id', $id))
            ->when(! $searching && ($data['assigned'] ?? null) === 'me', fn ($q) => $q->where('assigned_membership_id', $membership->id))
            ->when(! $searching && ($data['assigned'] ?? null) === 'unassigned', fn ($q) => $q->whereNull('assigned_membership_id'))
            ->when(! $searching && ($data['assigned'] ?? null) === 'others', fn ($q) => $q->whereNotNull('assigned_membership_id')->where('assigned_membership_id', '!=', $membership->id))
            ->when(($data['view'] ?? null) === 'bot', fn ($q) => $q->where('handler', 'bot'))
            ->when(($data['view'] ?? null) === 'waiting', fn ($q) => $q->where('last_message_direction', 'outbound')->where(fn ($w) => $w->whereNull('handler')->orWhere('handler', '!=', 'bot')))
            ->when(in_array($data['view'] ?? null, ['abandoned', 'completed', 'handed_off'], true), fn ($q) => $q->where('bot_state', $data['view']))
            ->when($request->boolean('unread'), fn ($q) => $q->where('unread_count', '>', 0))
            ->when(! $searching && ($data['state'] ?? null) === 'needs_reply', fn ($q) => $q->where('status', ConversationStatus::Open->value)->where('reply_state', ReplyState::NEEDS_REPLY))
            ->when(! $searching && ($data['state'] ?? null) === 'waiting', fn ($q) => $q->where('status', ConversationStatus::Open->value)->where(fn ($w) => $w->whereNull('reply_state')->orWhere('reply_state', ReplyState::WAITING)))
            ->when($request->boolean('after_hours'), fn ($q) => $q->where('reply_state', ReplyState::NEEDS_REPLY)->where('after_hours', true))
            ->when(($data['window'] ?? null) === 'open', fn ($q) => $q->where('window_expires_at', '>', now()))
            // Closing within 2 hours (Action Centre): still a free reply, but not for long.
            ->when(($data['window'] ?? null) === 'closing', fn ($q) => $q->where('window_expires_at', '>', now())->where('window_expires_at', '<=', now()->addHours(2)))
            ->when(($data['window'] ?? null) === 'closed', fn ($q) => $q->where(fn ($w) => $w->whereNull('window_expires_at')->orWhere('window_expires_at', '<=', now())))
            ->when($request->boolean('followup'), fn ($q) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('next_steps')
                ->whereColumn('next_steps.contact_id', 'conversations.contact_id')->whereNull('next_steps.done_at')->whereNull('next_steps.cancelled_at')))
            ->when($data['tag'] ?? null, fn ($q, string $tag) => $q->whereHas('contact', fn ($c) => $c->whereRaw('tags @> ARRAY[?]::text[]', [$tag])))
            // Snoozed conversations stay out of the way until their time (or until the customer writes).
            // Search covers everything; All (include_snoozed) lists them with their wake-up time.
            ->when($request->boolean('snoozed'), fn ($q) => $q->where('snoozed_until', '>', now()),
                fn ($q) => $searching || $request->boolean('include_snoozed') ? $q : $q->where(fn ($w) => $w->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now())))
            ->when($searching ? trim((string) $data['q']) : null, function ($q, string $term) {
                $digits = ltrim((string) preg_replace('/\D+/', '', $term), '0');
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($outer) => $outer
                    ->whereHas('contact', fn ($c) => $c->withTrashed()->where(fn ($w) => $w
                        ->where('name', 'ilike', $like)
                        ->orWhere('profile_name', 'ilike', $like)
                        ->orWhere('username', 'ilike', $like)
                        ->orWhere('email', 'ilike', $like)
                        ->orWhere('company', 'ilike', $like)
                        ->when(strlen($digits) >= 3, fn ($w) => $w->orWhere('wa_id', 'like', "%{$digits}%"))))
                    // …and what was written in the chat.
                    ->orWhereExists(fn ($m) => $m->selectRaw('1')->from('messages')->whereColumn('messages.conversation_id', 'conversations.id')
                        ->where('messages.body', 'ilike', $like)));
            });

        $query->addSelect(['conversations.*', 'last_sender' => Message::query()->selectRaw("concat(origin, ':', coalesce(sent_by_membership_id::text, ''))")
            ->whereColumn('messages.conversation_id', 'conversations.id')->orderByDesc('created_at')->limit(1)]);

        $perPage = (int) ($data['per_page'] ?? 30);
        $sort = $data['sort'] ?? null;
        if ($sort !== null) {
            // Offset pages ("cursor" = page number): the priority order is worked out, not a column.
            $page = max(0, (int) ($data['cursor'] ?? 0));
            match ($sort) {
                'newest' => $query->orderByDesc('last_message_at')->orderByDesc('id'),
                'waiting' => $query->orderByRaw('needs_reply_since ASC NULLS LAST')->tap(fn ($q) => self::priority($q)),
                default => self::priority($query),
            };
            $rows = $query->offset($page * $perPage)->limit($perPage + 1)->get();
            $more = $rows->count() > $perPage;
            $rows = $rows->take($perPage);
            self::withSteps($request, $rows);

            return ConversationResource::collection($rows)->additional(['meta' => ['next_cursor' => $more ? (string) ($page + 1) : null, 'per_page' => $perPage]]);
        }

        // Needs reply: ad leads first (they cost money), then the longest waiting.
        ($data['state'] ?? null) === 'needs_reply' || $request->boolean('after_hours')
            ? $query->orderBy('reply_due_at')->orderBy('needs_reply_since')->orderByDesc('ad_lead')->orderBy('id')
            : $query->orderByDesc('last_message_at')->orderByDesc('id');
        $page = $query->cursorPaginate($perPage);
        self::withSteps($request, collect($page->items()));

        return ConversationResource::collection($page);
    }

    /**
     * Priority (3.5, mobile brief v5): needs a reply with the window open (soonest to close first), needs a
     * reply with the window closed (longest waiting first), waiting for the customer (latest activity),
     * snoozed (soonest wake-up), resolved (latest).
     *
     * @param  Builder<Conversation>  $q
     */
    private static function priority($q): void
    {
        // The app's clock, not the database's, so every server and test agrees on "now".
        // Needs reply first (mobile brief v5): chats whose 24-hour window is still open, the one
        // closing soonest first (it can still get a free reply); then those whose window has closed,
        // longest waiting first. Then waiting on the customer, snoozed and resolved chats.
        $now = now()->toIso8601String();
        $needs = "status = 'open' AND reply_state = '".ReplyState::NEEDS_REPLY."' AND (snoozed_until IS NULL OR snoozed_until <= ?::timestamptz)";
        $open = 'window_expires_at > ?::timestamptz';
        $q->orderByRaw("CASE WHEN {$needs} AND {$open} THEN 0 WHEN {$needs} THEN 1 WHEN status = 'closed' THEN 4 WHEN snoozed_until > ?::timestamptz THEN 3 ELSE 2 END", [$now, $now, $now, $now])
            ->orderByRaw("CASE WHEN {$needs} AND {$open} THEN window_expires_at END ASC NULLS LAST", [$now, $now])
            ->orderByRaw("CASE WHEN {$needs} THEN needs_reply_since END ASC NULLS LAST", [$now])
            ->orderByRaw('CASE WHEN snoozed_until > ?::timestamptz THEN snoozed_until END ASC NULLS LAST', [$now])
            ->orderByDesc('last_message_at')->orderByDesc('id');
    }

    /**
     * The earliest open next step of each listed contact (the card's follow-up chip, "overdue").
     *
     * @param  Collection<int, Conversation>  $rows
     */
    private static function withSteps(Request $request, $rows): void
    {
        $ids = $rows->pluck('contact_id')->filter()->unique()->values();
        $steps = $ids->isEmpty() ? collect() : DB::table('next_steps')->whereIn('contact_id', $ids)->whereNull('done_at')->whereNull('cancelled_at')
            ->orderBy('due_at')->get(['id', 'contact_id', 'enquiry_id', 'type', 'title', 'due_at', 'assigned_membership_id'])->groupBy('contact_id');
        $request->attributes->set('inbox.steps', $steps->map(fn ($g) => [
            'first' => $g->first(),
            'overdue' => $g->filter(fn ($s) => Carbon::parse($s->due_at)->isPast())->count(),
            'open' => $g->count(),
        ])->all());
        ContactResource::recordFor($request)->preload($rows->pluck('contact')->filter());
    }

    /**
     * Counts for the inbox tabs (Needs reply, Arrived after hours, Unassigned, Mine), with the same
     * visibility rules as the list (agents: their own chats).
     */
    public function counts(Request $request, InboxCounts $counts): JsonResponse
    {
        $data = $request->validate(['phone_number_id' => ['nullable', 'uuid']]);
        $c = $counts->counts($this->membership(), $data['phone_number_id'] ?? null);

        return response()->json(['data' => [
            'needs_reply' => $c['needs_reply'],
            'needs_reply_mine' => $c['needs_reply_mine'],
            'after_hours' => $c['after_hours'],
            'unassigned' => $c['unassigned'],
        ]]);
    }

    /**
     * Who this conversation can be assigned to: active teammates, each marked with whether they can
     * use the conversation's WhatsApp number (Owners and Admins: every number; others: their grants).
     */
    public function assignees(Conversation $conversation): JsonResponse
    {
        $this->authorizeThread($conversation);
        $this->context->authorize(Permission::InboxAssign);
        $number = $conversation->phoneNumber()->firstOrFail();

        $members = TenantMembership::query()->active()->with(['user', 'role'])->orderBy('created_at')->get();

        return response()->json(['data' => $members->map(fn (TenantMembership $m) => [
            'id' => $m->id,
            'name' => $m->user->name ?? 'Teammate',
            'role' => $m->role?->name,
            'can_access' => $this->access->canAccess($m, $number),
        ])->values()]);
    }

    public function show(Conversation $conversation): ConversationResource
    {
        $this->authorizeThread($conversation);

        $conversation->setAttribute('last_sender', Message::query()->where('conversation_id', $conversation->id)->orderByDesc('created_at')
            ->selectRaw("concat(origin, ':', coalesce(sent_by_membership_id::text, '')) AS s")->value('s'));

        // What the first reply will start with, shown above the reply box before sending.
        $conversation->setAttribute('intro_preview', ReplyPromise::introLine($conversation, $this->membership()));

        return ConversationResource::make($conversation->load(['contact.stage', 'phoneNumber']));
    }

    /** Inbox health (3.5): window expiry rate, human and automation first response, sales follow-up. */
    public function health(Request $request, InboxHealth $health): JsonResponse
    {
        $id = $request->validate(['phone_number_id' => ['nullable', 'uuid']])['phone_number_id'] ?? null;

        return response()->json(['data' => $health->report($this->membership(), $id)]);
    }

    /** "I'm typing" (3.5): told to the teammates who have this chat open. Throttled by the client. */
    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeThread($conversation);
        $typing = $request->validate(['typing' => ['required', 'boolean']])['typing'];
        $me = $this->membership();
        try {
            TeammateTyping::dispatch((string) $conversation->tenant_id, $conversation->id, $me->id, (string) ($me->user()->value('name') ?? 'A teammate'), (bool) $typing);
        } catch (\Throwable) {
            // Realtime is optional: without it the chat still warns before a duplicate reply is sent.
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Mark as unread (3.5, the chat's ⋯ menu): the chat shows its unread dot again. */
    public function unread(Conversation $conversation): ConversationResource
    {
        $this->authorizeThread($conversation);
        $conversation->forceFill(['unread_count' => max(1, (int) $conversation->unread_count)])->save();

        return $this->show($conversation);
    }

    /**
     * The chat's event lines (3.5): assignments, closures and reopenings, snoozes, the bot stopping,
     * consent, stage moves, enquiries and next steps, shown between the messages and in the panel.
     */
    public function events(Conversation $conversation): JsonResponse
    {
        $this->authorizeThread($conversation);
        $types = ['conversation.assigned', 'conversation.snoozed', 'conversation.unsnoozed', 'consent.changed', 'stage.changed', 'enquiry.started',
            'flow.ended', 'next_step.added', 'next_step.done', 'next_step.cancelled', 'enquiry.updated', 'contact.updated'];
        $rows = DB::table('timeline_events')->where('contact_id', $conversation->contact_id)->whereIn('type', $types)
            ->where(fn ($w) => $w->where('subject_type', '!=', 'conversation')->orWhereNull('subject_type')->orWhere('subject_id', $conversation->id))
            ->orderByDesc('occurred_at')->limit(200)->get();
        $names = Resolutions::names();
        $out = $rows->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'at' => Carbon::parse($e->occurred_at)->toIso8601String(),
            'actor' => $e->actor, 'actor_name' => $e->actor_name, 'data' => json_decode((string) $e->data, true) ?: (object) []])->all();
        foreach (DB::table('conversation_resolutions')->where('conversation_id', $conversation->id)->get() as $r) {
            $p = Resolutions::present($r, $names);
            $out[] = ['id' => 'res-'.$r->id, 'type' => 'conversation.resolved', 'at' => $p['resolved_at'], 'actor' => $p['resolved_by'] ? 'agent' : 'system',
                'actor_name' => $p['resolved_by']['name'] ?? null, 'data' => ['reason' => $p['reason'], 'reason_label' => $p['reason_label']]];
            if ($p['reopened_at'] !== null) {
                $out[] = ['id' => 'reo-'.$r->id, 'type' => 'conversation.reopened', 'at' => $p['reopened_at'], 'actor' => $p['reopened_by'] ? 'agent' : 'system',
                    'actor_name' => $p['reopened_by']['name'] ?? null, 'data' => ['how' => $p['reopen_how']]];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return response()->json(['data' => $out]);
    }

    /** Open / close and (re)assign. Assignment needs inbox.assign; anyone with access may close. */
    public function update(Request $request, Conversation $conversation, AuditLogger $audit): ConversationResource
    {
        $this->authorizeThread($conversation);
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(ConversationStatus::class)],
            // Why it was resolved (3.5): Resolved, Not a lead / spam, No action needed.
            'reason' => ['sometimes', Rule::in(array_keys(Resolutions::REASONS))],
            // Undo straight after resolving: the closure is removed and the chat is as it was.
            'undo' => ['sometimes', 'boolean'],
            'assigned_membership_id' => ['sometimes', 'nullable', 'uuid'],
            // Shared inbox: who the person saw as the assignee. If a teammate changed it meanwhile, the
            // change is refused (409) instead of silently taking the chat from them.
            'expected_assigned_membership_id' => ['sometimes', 'nullable', 'uuid'],
            'assigned_team_id' => ['sometimes', 'nullable', 'uuid'],
            // false = no automatic replies in this conversation (a person is handling it).
            'auto_reply_enabled' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('assigned_membership_id', $data)) {
            $this->context->authorize(Permission::InboxAssign);
            if ($data['assigned_membership_id'] !== null) {
                $assignee = TenantMembership::query()->with(['role', 'user'])->findOrFail($data['assigned_membership_id']);
                $number = $conversation->phoneNumber()->firstOrFail();
                $name = $assignee->user->name ?? 'This teammate';
                // The check is about the person being assigned, not the one assigning: say so.
                if (! $assignee->isActive()) {
                    throw ValidationException::withMessages(['assigned_membership_id' => "{$name} is not an active member of this workspace."]);
                }
                if (! $this->access->canAccess($assignee, $number)) {
                    throw ValidationException::withMessages(['assigned_membership_id' => "{$name} doesn't have access to {$number->display_phone_number}. Give them this number in Team & Roles, or assign someone else."]);
                }
            }
        }

        if (array_key_exists('assigned_team_id', $data)) {
            $this->context->authorize(Permission::InboxAssign);
            if ($data['assigned_team_id'] !== null && ! InboxTeam::query()->whereKey($data['assigned_team_id'])->exists()) {
                throw ValidationException::withMessages(['assigned_team_id' => 'Choose one of your inbox teams.']);
            }
        }

        $before = ['status' => $conversation->status->value, 'assigned_membership_id' => $conversation->assigned_membership_id, 'assigned_team_id' => $conversation->assigned_team_id];

        if (array_key_exists('auto_reply_enabled', $data)) {
            $conversation->setAttribute('auto_reply_enabled', (bool) $data['auto_reply_enabled']);
        }
        if (isset($data['status'])) {
            $closing = $data['status'] === ConversationStatus::Closed->value && $before['status'] !== 'closed';
            $reopening = $data['status'] === ConversationStatus::Open->value && $before['status'] === 'closed';
            $conversation->status = ConversationStatus::from($data['status']);
            $conversation->closed_at = $data['status'] === ConversationStatus::Closed->value ? now() : null;
            // Resolved by an agent: the wait ends and the resolution time is recorded.
            if ($closing) {
                $conversation->forceFill(ReplyState::closed($conversation, $this->membership()->id, $data['reason'] ?? 'resolved'));
            } elseif ($reopening && ($data['undo'] ?? false) && Resolutions::undo($conversation, $this->membership()->id)) {
                // Undone: who it waits on is worked out again from the messages, as if it was never closed.
                $conversation->forceFill(['reply_state' => null, 'needs_reply_since' => null]);
                $conversation->forceFill(ReplyState::recalculate($conversation));
            } elseif ($reopening) {
                Resolutions::reopened($conversation, 'agent', $this->membership()->id);
                $conversation->forceFill(ReplyState::reopened($conversation));
                $alertsFromNow = true;
            }
        }
        if (array_key_exists('assigned_membership_id', $data)) {
            if (array_key_exists('expected_assigned_membership_id', $data)) {
                // One conditional update: of two people taking the same chat at once, only the first wins.
                $expected = $data['expected_assigned_membership_id'];
                $target = $data['assigned_membership_id'];
                $claimed = Conversation::query()->whereKey($conversation->id)
                    ->where(fn ($q) => $q->where(fn ($w) => $expected === null ? $w->whereNull('assigned_membership_id') : $w->where('assigned_membership_id', $expected))
                        ->orWhere(fn ($w) => $target === null ? $w->whereNull('assigned_membership_id') : $w->where('assigned_membership_id', $target)))
                    ->update(['assigned_membership_id' => $target]);
                if ($claimed === 0) {
                    $current = Conversation::query()->whereKey($conversation->id)->value('assigned_membership_id');
                    $holder = $current !== null ? (TenantMembership::query()->with('user')->find($current)?->user->name ?? 'A teammate') : null;
                    abort(response()->json(['error' => [
                        'code' => 'assignment_changed',
                        'message' => $holder !== null
                            ? "{$holder} has just been given this chat. Open it to reassign if you need to."
                            : 'This chat was just moved to Unassigned. Refresh and try again.',
                        'details' => ['assigned_membership_id' => $current],
                    ]], 409));
                }
            }
            $conversation->assigned_membership_id = $data['assigned_membership_id'];
        }
        if (array_key_exists('assigned_team_id', $data)) {
            $conversation->assigned_team_id = $data['assigned_team_id'];
        }
        $conversation->save();
        if ($alertsFromNow ?? false) {
            ReplyState::alertsFromNow($conversation);
        }
        // Closing ends any chatbot session; the next customer message may start the bot again.
        if (($data['status'] ?? null) === ConversationStatus::Closed->value && $before['status'] !== 'closed') {
            app(Chatbots::class)->stop($conversation, 'closed');
            $conversation->refresh();
            // CSAT: a one-tap rating for a support chat, inside the 24-hour window only (Follow-ups & Alerts).
            app(FeedbackRequests::class)->afterClose($conversation, $this->membership()->id);
            $conversation->refresh();
        }

        // Tell a teammate when someone else gives them a conversation (bell + email).
        $assignee = $data['assigned_membership_id'] ?? null;
        if ($assignee !== null && $assignee !== $before['assigned_membership_id'] && $assignee !== $this->membership()->id) {
            $member = TenantMembership::query()->find($assignee);
            if ($member !== null) {
                $by = $this->membership()->user()->value('name') ?? 'A teammate';
                app(InboxTools::class)->notify($member, 'assigned', "{$by} assigned a conversation to you", $conversation->contact()->first()?->displayName(), "/inbox?c={$conversation->id}", email: true);
            }
        }

        $audit->record('conversation.updated', $conversation, before: $before, after: $data);

        // Customer webhooks for assigning and closing are sent from the contact timeline (TimelineWebhooks).

        return ConversationResource::make($conversation->load(['contact.stage', 'phoneNumber']));
    }

    /** "Take over": the chatbot stops answering in this conversation; a person handles it. */
    public function takeOver(Conversation $conversation, Chatbots $chatbots, AuditLogger $audit): ConversationResource
    {
        $this->authorizeThread($conversation);
        $chatbots->takeOver($conversation);
        // A person took over: customer nudges stop.
        foreach (FollowUps::NUDGE_KINDS as $kind) {
            app(Scheduler::class)->cancelForConversation($conversation->id, 'taken_over', $kind);
        }
        if ($conversation->assigned_membership_id === null) {
            $conversation->forceFill(['assigned_membership_id' => $this->membership()->id])->save();
        }
        $audit->record('conversation.bot_taken_over', $conversation);

        return ConversationResource::make($conversation->refresh()->load(['contact.stage', 'phoneNumber']));
    }

    /** "Hand back to bot": the bot takes the conversation again and answers the customer's next message (nothing is sent now). */
    public function handBack(Request $request, Conversation $conversation, Chatbots $chatbots, AuditLogger $audit): JsonResponse
    {
        $this->authorizeThread($conversation);
        $restart = $request->boolean('restart', false); // default: send nothing, the bot answers the customer's next message
        $outcome = $chatbots->handBackOutcome($conversation, $restart);
        $started = $outcome === 'started';
        $audit->record('conversation.bot_handed_back', $conversation, meta: ['restarted' => $started, 'outcome' => $outcome]);

        return ConversationResource::make($conversation->refresh()->load(['contact.stage', 'phoneNumber']))
            ->additional(['meta' => ['restarted' => $started, 'outcome' => $outcome]])->response();
    }

    /** Clear unread and send blue ticks for the latest customer message. */
    public function read(Conversation $conversation): ConversationResource
    {
        $this->authorizeThread($conversation);

        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();

            $latest = Message::query()->where('conversation_id', $conversation->id)->where('direction', Message::INBOUND)
                ->whereNotNull('wamid')->where('origin', 'customer')->latest('meta_timestamp')->first();
            if ($latest !== null && $latest->meta_timestamp?->gt(now()->subDays(30))) {
                SendReadReceipt::dispatch($conversation->phone_number_id, (string) $latest->wamid)->afterCommit();
            }
        }

        return ConversationResource::make($conversation->load(['contact.stage', 'phoneNumber']));
    }

    private function membership(): TenantMembership
    {
        return $this->context->membership()?->loadMissing('role') ?? abort(403);
    }

    private function authorizeThread(Conversation $conversation): void
    {
        $this->access->ensureCanSeeChat($this->membership(), $conversation);
    }
}
