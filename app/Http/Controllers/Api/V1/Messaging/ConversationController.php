<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditLogger;
use App\Domain\Automation\FollowUps;
use App\Domain\Automation\Scheduler;
use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Developer\Services\PublicPayload;
use App\Domain\Developer\Services\WebhookDispatcher;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Jobs\SendReadReceipt;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\InboxCounts;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Messaging\Services\ReplyState;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ConversationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
            // Chatbot views: handled by the bot now, waiting on the customer, or how the bot ended.
            'view' => ['nullable', Rule::in(['bot', 'waiting', 'abandoned', 'completed', 'handed_off'])],
            'unread' => ['nullable', 'boolean'],
            'snoozed' => ['nullable', 'boolean'],
            // Who it is waiting on (Engagement Plan §II); after_hours = "Arrived after hours".
            'state' => ['nullable', Rule::in(['needs_reply', 'waiting'])],
            'after_hours' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $membership = $this->membership();

        $query = Conversation::query()->with(['contact.stage', 'phoneNumber'])->whereNotNull('last_message_at');
        $this->access->scopeChats($query, $membership); // agents: only the chats assigned to them

        $query
            ->when($data['phone_number_id'] ?? null, fn ($q, $id) => $q->where('phone_number_id', $id))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when(($data['assigned'] ?? null) === 'me', fn ($q) => $q->where('assigned_membership_id', $membership->id))
            ->when(($data['assigned'] ?? null) === 'unassigned', fn ($q) => $q->whereNull('assigned_membership_id'))
            ->when(($data['assigned'] ?? null) === 'others', fn ($q) => $q->whereNotNull('assigned_membership_id')->where('assigned_membership_id', '!=', $membership->id))
            ->when(($data['view'] ?? null) === 'bot', fn ($q) => $q->where('handler', 'bot'))
            ->when(($data['view'] ?? null) === 'waiting', fn ($q) => $q->where('last_message_direction', 'outbound')->where(fn ($w) => $w->whereNull('handler')->orWhere('handler', '!=', 'bot')))
            ->when(in_array($data['view'] ?? null, ['abandoned', 'completed', 'handed_off'], true), fn ($q) => $q->where('bot_state', $data['view']))
            ->when($request->boolean('unread'), fn ($q) => $q->where('unread_count', '>', 0))
            ->when(($data['state'] ?? null) === 'needs_reply', fn ($q) => $q->where('status', ConversationStatus::Open->value)->where('reply_state', ReplyState::NEEDS_REPLY))
            ->when(($data['state'] ?? null) === 'waiting', fn ($q) => $q->where('status', ConversationStatus::Open->value)->where(fn ($w) => $w->whereNull('reply_state')->orWhere('reply_state', ReplyState::WAITING)))
            ->when($request->boolean('after_hours'), fn ($q) => $q->where('reply_state', ReplyState::NEEDS_REPLY)->where('after_hours', true))
            // Snoozed conversations stay out of the way until their time (or until the customer writes).
            ->when($request->boolean('snoozed'), fn ($q) => $q->where('snoozed_until', '>', now()), fn ($q) => $q->where(fn ($w) => $w->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now())))
            ->when($data['q'] ?? null, function ($q, string $term) {
                $digits = preg_replace('/\D+/', '', $term);
                $q->whereHas('contact', fn ($c) => $c->withTrashed()->where(fn ($w) => $w
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('profile_name', 'ilike', "%{$term}%")
                    ->when($digits !== '', fn ($w) => $w->orWhere('wa_id', 'like', "%{$digits}%"))));
            })
            // Needs reply: ad leads first (they cost money), then the longest waiting.
            ->when(($data['state'] ?? null) === 'needs_reply' || $request->boolean('after_hours'), fn ($q) => $q->orderByDesc('ad_lead')->orderBy('needs_reply_since')->orderBy('id'),
                fn ($q) => $q->orderByDesc('last_message_at')->orderByDesc('id'));

        return ConversationResource::collection($query->cursorPaginate((int) ($data['per_page'] ?? 30)));
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

        return ConversationResource::make($conversation->load(['contact.stage', 'phoneNumber']));
    }

    /** Open / close and (re)assign. Assignment needs inbox.assign; anyone with access may close. */
    public function update(Request $request, Conversation $conversation, AuditLogger $audit): ConversationResource
    {
        $this->authorizeThread($conversation);
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(ConversationStatus::class)],
            'assigned_membership_id' => ['sometimes', 'nullable', 'uuid'],
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

        $before = ['status' => $conversation->status->value, 'assigned_membership_id' => $conversation->assigned_membership_id];

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
                $conversation->forceFill(ReplyState::closed($conversation, $this->membership()->id));
            } elseif ($reopening) {
                $conversation->forceFill(ReplyState::reopened());
            }
        }
        if (array_key_exists('assigned_membership_id', $data)) {
            $conversation->assigned_membership_id = $data['assigned_membership_id'];
        }
        $conversation->save();
        // Closing ends any chatbot session; the next customer message may start the bot again.
        if (($data['status'] ?? null) === ConversationStatus::Closed->value && $before['status'] !== 'closed') {
            app(Chatbots::class)->stop($conversation, 'closed');
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

        // Customer webhooks.
        $conversation->loadMissing('contact');
        if ($assignee !== null && $assignee !== $before['assigned_membership_id']) {
            app(WebhookDispatcher::class)->emit($conversation->tenant_id, 'conversation.assigned', PublicPayload::conversation($conversation));
        }
        if (($data['status'] ?? null) === 'closed' && $before['status'] !== 'closed') {
            app(WebhookDispatcher::class)->emit($conversation->tenant_id, 'conversation.closed', PublicPayload::conversation($conversation));
        }

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
