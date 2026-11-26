<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Chatbots\Engine\Placeholders;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Models\ChatbotFlowVersion;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Crm\Models\NextStep;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Messaging\Services\ReplyPromise;
use App\Domain\Messaging\Services\ReplyState;
use App\Domain\Messaging\Services\Resolutions;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** @mixin Conversation */
final class ConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone_number_id' => $this->phone_number_id,
            'status' => $this->status->value,
            'snoozed_until' => ($until = $this->getAttribute('snoozed_until')) !== null && $until->isFuture() ? $until->toIso8601String() : null,
            'auto_reply_enabled' => (bool) ($this->getAttribute('auto_reply_enabled') ?? true),
            'assigned_membership_id' => $this->assigned_membership_id,
            // The assignee's name for the wait clock ("Waiting 0:42 · Ahmed").
            'assigned_name' => $this->assigned_membership_id !== null ? (self::memberNames($request)[$this->assigned_membership_id] ?? null) : null,
            'assigned_team_id' => $this->assigned_team_id,
            // Who answers now: "bot" (a chatbot session is running), "human", or null (nobody yet).
            'handler' => $this->getAttribute('handler'),
            'bot' => $this->bot(),
            // A chatbot is live on this number, so an agent can hand the conversation (back) to it.
            'bot_available' => $this->botAvailable($request),
            // A person handles it and the flow auto-resumes: the bot takes the conversation back at the
            // customer's next message from this time on (null = only "Hand back to bot" or closing does).
            'bot_resumes_at' => $this->getAttribute('handler') === 'human' && $this->botAvailable($request)
                ? app(Chatbots::class)->autoResumeAt($this->resource)?->toIso8601String() : null,
            // Who the chat is waiting on (Engagement Plan §II): needs_reply | waiting | snoozed | closed.
            'state' => ReplyState::shown($this->resource),
            // Needs reply: since when the customer has been waiting (the timer), whether it arrived after
            // hours, whether it came from an ad (top of the morning queue).
            'needs_reply_since' => $this->getAttribute('reply_state') === ReplyState::NEEDS_REPLY ? $this->getAttribute('needs_reply_since')?->toIso8601String() : null,
            'after_hours' => (bool) $this->getAttribute('after_hours'),
            'ad_lead' => (bool) $this->getAttribute('ad_lead'),
            // The reply promise (3.3, A3): business time waited and left on the limit, paused outside hours.
            'promise' => ReplyPromise::clock(app(TenantContext::class)->tenantOrNull(), $this->resource),
            // Closed: who resolved it, when and why (3.5).
            'resolution' => $this->status === ConversationStatus::Closed ? (($r = Resolutions::current((string) $this->id)) !== null ? Resolutions::present($r, self::memberNames($request)) : null) : null,
            // Who wrote the last message, for the card's "You:", "Auto:", "Sara:" prefix (3.5).
            'last_message_by' => $this->lastSender($request),
            // The contact's earliest open next step and how many are overdue (the card's follow-up chip).
            'next_step' => $this->nextStep($request),
            'unread_count' => $this->unread_count,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_message_preview' => $this->getAttribute('last_message_preview'),
            'last_message_direction' => $this->getAttribute('last_message_direction'),
            'window' => [
                'open' => $this->isWindowOpen(),
                'expires_at' => $this->window_expires_at?->toIso8601String(),
                // "Reply before window closes": needs a reply and the 24 h window ends soon.
                'closing_soon' => $this->getAttribute('reply_state') === ReplyState::NEEDS_REPLY && $this->isWindowOpen()
                    && $this->window_expires_at !== null && $this->window_expires_at->lte(now()->addMinutes(self::warningMinutes($request))),
                // Whether a normal message can be sent now (window open, and for an opted-out contact: they wrote after opting out).
                'replies_allowed' => $this->relationLoaded('contact') && $this->contact !== null ? $this->acceptsFreeFormFrom($this->contact) : $this->isWindowOpen(),
            ],
            'contact' => ContactResource::make($this->whenLoaded('contact')),
            'phone_number' => $this->whenLoaded('phoneNumber', fn () => [
                'id' => $this->phoneNumber?->id,
                'display_phone_number' => $this->phoneNumber?->display_phone_number,
                'verified_name' => $this->phoneNumber?->verified_name,
                // "disconnected" once the number was offboarded: the conversation stays readable, but nothing can be sent.
                'status' => $this->phoneNumber?->status->value,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, string> membership id → name, loaded once per request */
    /** @return array{kind: string, name: ?string}|null */
    private function lastSender(Request $request): ?array
    {
        $raw = $this->getAttribute('last_sender');
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        [$origin, $member] = array_pad(explode(':', $raw, 2), 2, '');
        $me = app(TenantContext::class)->membership()?->id;

        return match ($origin) {
            'customer', 'history' => ['kind' => 'customer', 'name' => null],
            'agent' => $member !== '' && $member === $me ? ['kind' => 'you', 'name' => 'You'] : ['kind' => 'agent', 'name' => $member !== '' ? strtok(self::memberNames($request)[$member] ?? 'Teammate', ' ') : 'Teammate'],
            'app_echo' => ['kind' => 'agent', 'name' => 'App'],
            default => ['kind' => 'auto', 'name' => 'Auto'],
        };
    }

    /** @return array<string, mixed>|null */
    private function nextStep(Request $request): ?array
    {
        $all = $request->attributes->get('inbox.steps');
        if (! is_array($all)) {
            $rows = DB::table('next_steps')->where('contact_id', $this->contact_id)->whereNull('done_at')->whereNull('cancelled_at')->orderBy('due_at')
                ->get(['id', 'contact_id', 'enquiry_id', 'type', 'title', 'due_at', 'assigned_membership_id']);
            $mine = $rows->isEmpty() ? null : ['first' => $rows->first(), 'open' => $rows->count(), 'overdue' => $rows->filter(fn ($r) => Carbon::parse($r->due_at)->isPast())->count()];
        } else {
            $mine = $all[$this->contact_id] ?? null;
        }
        if ($mine === null) {
            return null;
        }
        $f = $mine['first'];

        return [
            'id' => $f->id, 'type' => $f->type, 'type_label' => NextStep::TYPES[$f->type] ?? 'Other', 'title' => $f->title,
            'due_at' => Carbon::parse($f->due_at)->toIso8601String(), 'enquiry_id' => $f->enquiry_id,
            'open' => $mine['open'], 'overdue' => $mine['overdue'],
        ];
    }

    /** @return array<string, string> membership id → name */
    private static function memberNames(Request $request): array
    {
        if (! $request->attributes->has('inbox.member_names')) {
            $request->attributes->set('inbox.member_names', DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')
                ->pluck('users.name', 'tenant_memberships.id')->all());
        }

        return (array) $request->attributes->get('inbox.member_names');
    }

    private static function warningMinutes(Request $request): int
    {
        if (! $request->attributes->has('inbox.window_warning')) {
            $request->attributes->set('inbox.window_warning', InboxTools::settings(app(TenantContext::class)->tenantOrNull())['window_warning_minutes']);
        }

        return (int) $request->attributes->get('inbox.window_warning');
    }

    /** Looked up once per number per request (an inbox list shows many conversations on few numbers). */
    private function botAvailable(Request $request): bool
    {
        $key = 'chatbots.live_on.'.$this->phone_number_id;
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, ChatbotFlow::anyLiveOn($this->phone_number_id));
        }

        return (bool) $request->attributes->get($key);
    }

    /**
     * The conversation's latest chatbot run: which flow, its state, and the step the customer
     * reached (for an abandoned flow: the step they left at).
     *
     * @return ?array<string, mixed>
     */
    private function bot(): ?array
    {
        $id = $this->getAttribute('bot_session_id');
        if ($id === null) {
            return null;
        }
        $session = ChatbotSession::query()->with('flow:id,name')->find($id);
        if ($session === null) {
            return null;
        }
        $node = $session->ended_node ?? $session->current_node;

        // What the customer answered, for the agent who takes over (handoff details).
        $answers = [];
        foreach ((array) ($session->vars ?? []) as $key => $value) {
            if (! str_starts_with((string) $key, '_') && ! in_array($key, ['last_input', 'http_status', 'started_from_ad'], true) && $value !== null && $value !== '' && count($answers) < 12) {
                $answers[] = ['key' => $this->nodeTitle($session, (string) $key) ?? str_replace('_', ' ', (string) $key), 'value' => mb_substr(Placeholders::text($value), 0, 200)];
            }
        }

        return [
            'state' => $this->getAttribute('bot_state'),
            'flow_id' => $session->flow_id,
            'flow_name' => $session->flow?->name,
            'bot_name' => $session->flow->name ?? 'Chatbot',
            'answers' => $answers,
            'node_id' => $node,
            'node_title' => $node !== null ? $this->nodeTitle($session, $node) : null,
            'ended_reason' => $session->ended_reason,
            'ended_at' => $session->ended_at?->toIso8601String(),
            'started_at' => $session->created_at?->toIso8601String(),
        ];
    }

    private function nodeTitle(ChatbotSession $session, string $node): ?string
    {
        // Node titles per flow version, read once per request (a page lists many conversations).
        static $titles = [];
        $key = "{$session->flow_id}:{$session->flow_version}";
        if (! isset($titles[$key])) {
            $graph = ChatbotFlowVersion::query()->where('flow_id', $session->flow_id)->where('version', $session->flow_version)->first()?->graph;
            $titles[$key] = collect(is_array($graph) ? (array) ($graph['nodes'] ?? []) : [])
                ->filter(fn ($n) => is_array($n) && isset($n['id']))->mapWithKeys(fn (array $n) => [$n['id'] => $n['title'] ?? null])->all();
        }

        return $titles[$key][$node] ?? null;
    }
}
