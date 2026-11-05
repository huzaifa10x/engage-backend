<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Audit\AuditLogger;
use App\Domain\Automation\Scheduler;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Models\CannedResponse;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationNote;
use App\Domain\Messaging\Models\MemberNotification;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\BusinessHours;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ConversationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Canned responses, internal notes, snooze, business hours / routing, notifications, dashboard numbers. */
final class InboxToolsController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly NumberAccess $access,
        private readonly InboxTools $tools,
        private readonly AuditLogger $audit,
    ) {}

    // ── Canned responses ───────────────────────────────────────────────────────────────────

    public function cannedResponses(): JsonResponse
    {
        return response()->json(['data' => CannedResponse::query()->orderBy('shortcut')->get(['id', 'shortcut', 'body'])]);
    }

    public function saveCannedResponse(Request $request, ?CannedResponse $cannedResponse = null): JsonResponse
    {
        $tenant = $this->context->tenant();
        $data = $request->validate([
            'shortcut' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('canned_responses', 'shortcut')->where('tenant_id', $tenant->id)->ignore($cannedResponse?->id)],
            'body' => ['required', 'string', 'max:4096'],
        ], ['shortcut.regex' => 'Use lowercase letters, numbers, dashes or underscores (no spaces).', 'shortcut.unique' => 'This shortcut is already used.']);

        if ($cannedResponse === null) {
            $this->entitlements->ensureWithinLimit($tenant, FeatureKey::CannedResponses);
            $cannedResponse = CannedResponse::query()->create($data + ['created_by_membership_id' => $this->context->membership()?->id]);
        } else {
            $cannedResponse->fill($data)->save();
        }

        return response()->json(['data' => $cannedResponse->only(['id', 'shortcut', 'body'])], $cannedResponse->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteCannedResponse(CannedResponse $cannedResponse): JsonResponse
    {
        $cannedResponse->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ── Internal notes ─────────────────────────────────────────────────────────────────────

    public function notes(Conversation $conversation): JsonResponse
    {
        $this->authorizeThread($conversation);
        $notes = ConversationNote::query()->where('conversation_id', $conversation->id)->orderBy('created_at')->orderBy('id')->limit(200)->get();
        $names = $this->memberNames($notes->pluck('membership_id')->merge($notes->pluck('mentions')->flatten())->filter()->unique()->all());

        return response()->json(['data' => $notes->map(fn (ConversationNote $n) => [
            'id' => $n->id,
            'body' => $n->body,
            'author' => $n->membership_id !== null ? ($names[$n->membership_id] ?? 'Former member') : ($n->author_name ?? 'System'),
            'by_bot' => $n->membership_id === null && $n->author_name !== null,
            'mine' => $n->membership_id === $this->context->membership()?->id,
            'mentions' => array_values(array_filter(array_map(fn (string $id) => $names[$id] ?? null, $n->mentions ?? []))),
            'created_at' => $n->created_at?->toIso8601String(),
        ])]);
    }

    public function addNote(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeThread($conversation);
        $this->entitlements->ensureEnabled($this->context->tenant(), FeatureKey::InternalNotes);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'mentions' => ['nullable', 'array', 'max:20'],
            'mentions.*' => ['uuid'],
        ]);
        $author = $this->context->membership();
        $mentioned = TenantMembership::query()->active()->whereIn('id', $data['mentions'] ?? [])->get();

        $note = ConversationNote::query()->create([
            'conversation_id' => $conversation->id, 'membership_id' => $author?->id, 'body' => $data['body'], 'mentions' => $mentioned->pluck('id')->all(),
        ]);

        $name = $author?->user()->value('name') ?? 'A teammate';
        foreach ($mentioned as $member) {
            if ($member->id !== $author?->id) {
                $this->tools->notify($member, 'mention', "{$name} mentioned you in a note", $data['body'], "/inbox?c={$conversation->id}", email: true);
            }
        }

        return response()->json(['data' => ['id' => $note->id]], 201);
    }

    // ── Snooze ─────────────────────────────────────────────────────────────────────────────

    /** Hide the conversation until a time; it comes back by itself then, or as soon as the customer writes. */
    public function snooze(Request $request, Conversation $conversation): ConversationResource
    {
        $this->authorizeThread($conversation);
        $this->entitlements->ensureEnabled($this->context->tenant(), FeatureKey::Snooze);
        $data = $request->validate(['until' => ['required', 'date', 'after:now', 'before:+90 days']]);

        $conversation->forceFill(['snoozed_until' => $data['until']])->save();
        $until = Carbon::parse($data['until']);
        // The scheduler brings it back as Needs reply at that time (any earlier snooze return is replaced).
        $scheduler = app(Scheduler::class);
        $scheduler->cancelForConversation($conversation->id, 'resnoozed', 'snooze_return');
        $scheduler->schedule('snooze_return', $until, "snooze:{$conversation->id}:{$until->getTimestamp()}", $conversation, payload: ['until' => $until->toIso8601String(), 'cancel_on_reply' => true]);
        $this->audit->record('conversation.snoozed', $conversation, after: ['until' => $data['until']]);

        return ConversationResource::make($conversation->load(['contact.stage', 'phoneNumber']));
    }

    public function unsnooze(Conversation $conversation): ConversationResource
    {
        $this->authorizeThread($conversation);
        $conversation->forceFill(['snoozed_until' => null])->save();
        app(Scheduler::class)->cancelForConversation($conversation->id, 'unsnoozed', 'snooze_return');

        return ConversationResource::make($conversation->load(['contact.stage', 'phoneNumber']));
    }

    // ── Business hours and routing ─────────────────────────────────────────────────────────

    public function inboxSettings(): JsonResponse
    {
        $tenant = $this->context->tenant();
        $set = $this->entitlements->for($tenant);

        return response()->json(['data' => InboxTools::settings($tenant) + [
            'open_now' => InboxTools::isOpen($tenant),
            // "We're back tomorrow at 9:00": what the after-hours reply says right now ({{business.next_open}}).
            'next_open' => BusinessHours::enabled($tenant) ? BusinessHours::nextOpenLabel($tenant) : null,
            'can' => ['business_hours' => $set->allows(FeatureKey::BusinessHours), 'auto_routing' => $set->allows(FeatureKey::AutoRouting)],
        ]]);
    }

    public function updateInboxSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_hours' => ['sometimes', 'array'],
            'business_hours.enabled' => ['required_with:business_hours', 'boolean'],
            'business_hours.days' => ['required_with:business_hours', 'array:'.implode(',', InboxTools::DAYS)],
            'business_hours.days.*.open' => ['required', 'boolean'],
            'business_hours.days.*.from' => ['required', 'date_format:H:i'],
            'business_hours.days.*.to' => ['required', 'date_format:H:i'],
            'routing' => ['sometimes', 'in:manual,round_robin'],
            // Different hours for some numbers (phone number id → week); holidays; reply targets.
            'number_hours' => ['sometimes', 'array', 'max:50'],
            'number_hours.*' => ['array:'.implode(',', InboxTools::DAYS)],
            'number_hours.*.*.open' => ['required', 'boolean'],
            'number_hours.*.*.from' => ['required', 'date_format:H:i'],
            'number_hours.*.*.to' => ['required', 'date_format:H:i'],
            'holidays' => ['sometimes', 'array', 'max:100'],
            'holidays.*.date' => ['required', 'date_format:Y-m-d'],
            'holidays.*.name' => ['nullable', 'string', 'max:60'],
            'reply_target_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'window_warning_minutes' => ['sometimes', 'integer', 'min:15', 'max:720'],
        ]);
        foreach ((array) ($data['number_hours'] ?? []) as $numberId => $week) {
            if (! is_string($numberId) || ! PhoneNumber::query()->whereKey($numberId)->exists()) { // RLS: this workspace's numbers
                throw ValidationException::withMessages(['number_hours' => 'Choose WhatsApp numbers connected to this workspace.']);
            }
            foreach ($week as $day => $hours) {
                if ($hours['open'] && $hours['from'] >= $hours['to']) {
                    throw ValidationException::withMessages(["number_hours.{$numberId}.{$day}.to" => 'Closing time must be after opening time.']);
                }
            }
        }
        if (isset($data['number_hours']) || isset($data['holidays'])) {
            $this->entitlements->ensureEnabled($this->context->tenant(), FeatureKey::BusinessHours);
        }
        $tenant = $this->context->tenant();
        if (($data['business_hours']['enabled'] ?? false) === true) {
            $this->entitlements->ensureEnabled($tenant, FeatureKey::BusinessHours);
            foreach ($data['business_hours']['days'] as $day => $hours) {
                if ($hours['open'] && $hours['from'] >= $hours['to']) {
                    throw ValidationException::withMessages(["business_hours.days.{$day}.to" => 'Closing time must be after opening time.']);
                }
            }
        }
        if (($data['routing'] ?? null) === 'round_robin') {
            $this->entitlements->ensureEnabled($tenant, FeatureKey::AutoRouting);
        }

        $settings = $tenant->settings ?? [];
        $before = (array) ($settings['inbox'] ?? []);
        // Lists are replaced as a whole (a removed holiday or number override disappears).
        $settings['inbox'] = array_merge($before, $data);
        $tenant->forceFill(['settings' => $settings])->save();
        $this->audit->record('tenant.inbox_settings_updated', $tenant, before: $before, after: $data);

        return $this->inboxSettings();
    }

    // ── Notifications (the bell) ───────────────────────────────────────────────────────────

    /**
     * Everything the header bell and the desktop notifier need in one small, cheap call:
     * the member's own notifications (assignments, mentions, returning snoozes) and the
     * conversations with unread customer messages that are theirs to answer.
     */
    public function notifications(): JsonResponse
    {
        $membership = $this->context->membership()?->loadMissing('role') ?? abort(403);
        $me = $membership->id;
        $items = MemberNotification::query()->where('membership_id', $me)->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get();

        // Unread conversations that are theirs to answer: assigned to them, or (Owner/Admin) not assigned
        // to anyone. Agents never see other people's chats here either.
        $unread = fn () => $this->access->scopeChats(Conversation::query(), $membership)
            ->where('status', ConversationStatus::Open->value)->where('unread_count', '>', 0)->whereNotNull('last_inbound_at')
            ->where(fn ($q) => $q->whereNull('assigned_membership_id')->orWhere('assigned_membership_id', $me))
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
        $conversations = $unread()->with('contact')->orderByDesc('last_inbound_at')->limit(10)->get();

        return response()->json(['data' => [
            'unread' => MemberNotification::query()->where('membership_id', $me)->whereNull('read_at')->count(),
            'items' => $items->map(fn (MemberNotification $n) => [
                'id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body, 'url' => $n->url,
                'read' => $n->read_at !== null, 'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'unread_conversations' => $unread()->count(),
            'messages' => $conversations->map(fn (Conversation $c) => [
                'conversation_id' => $c->id,
                'contact' => $c->contact?->displayName() ?? 'WhatsApp user',
                'preview' => $c->last_message_direction === Message::INBOUND ? (string) $c->last_message_preview : 'New message',
                'unread_count' => $c->unread_count,
                'at' => $c->last_inbound_at?->toIso8601String(),
                'url' => "/inbox?c={$c->id}",
            ]),
        ]]);
    }

    public function readNotifications(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['nullable', 'uuid']]);
        MemberNotification::query()->where('membership_id', $this->context->membership()?->id)->whereNull('read_at')
            ->when($data['id'] ?? null, fn ($q, $id) => $q->whereKey($id))->update(['read_at' => now()]);

        return $this->notifications();
    }

    // ── Dashboard numbers ──────────────────────────────────────────────────────────────────

    public function dashboard(): JsonResponse
    {
        $membership = $this->context->membership()?->loadMissing('role') ?? abort(403);
        $tenant = $this->context->tenant();
        $today = now()->setTimezone($tenant->timezone ?: 'UTC')->startOfDay()->utc();
        $month = now()->setTimezone($tenant->timezone ?: 'UTC')->startOfMonth()->utc();

        // Counts follow chat visibility: agents count only the chats assigned to them.
        $conversations = fn () => $this->access->scopeChats(Conversation::query()->whereNotNull('last_message_at'), $membership);
        $messages = fn () => $this->access->seesAllChats($membership)
            ? $this->access->scope(Message::query(), $membership, 'phone_number_id')
            : Message::query()->whereIn('conversation_id', $this->access->scopeChats(Conversation::query(), $membership)->select('id'));

        return response()->json(['data' => [
            'conversations_today' => $conversations()->where('last_inbound_at', '>=', $today)->count(),
            'open' => $conversations()->where('status', ConversationStatus::Open->value)->count(),
            'unassigned' => $conversations()->where('status', ConversationStatus::Open->value)->whereNull('assigned_membership_id')->count(),
            'waiting_for_reply' => $conversations()->where('status', ConversationStatus::Open->value)->where('last_message_direction', Message::INBOUND)->count(),
            'mine' => $conversations()->where('status', ConversationStatus::Open->value)->where('assigned_membership_id', $membership->id)->count(),
            'messages_sent_today' => $messages()->where('direction', Message::OUTBOUND)->where('created_at', '>=', $today)->count(),
            'messages_received_today' => $messages()->where('direction', Message::INBOUND)->where('created_at', '>=', $today)->count(),
            'campaign_messages_this_month' => $messages()->whereNotNull('campaign_id')->where('created_at', '>=', $month)->count(),
        ]]);
    }

    private function authorizeThread(Conversation $conversation): void
    {
        $membership = $this->context->membership()?->loadMissing('role') ?? abort(403);
        $this->access->ensureCanSeeChat($membership, $conversation);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<string, string> membership id → member name
     */
    private function memberNames(array $ids): array
    {
        return TenantMembership::query()->whereIn('id', $ids)->with('user:id,name')->get()->mapWithKeys(fn (TenantMembership $m) => [$m->id => (string) ($m->user->name ?? 'Member')])->all();
    }
}
