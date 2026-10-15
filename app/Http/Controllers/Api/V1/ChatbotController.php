<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permission;
use App\Domain\Audit\AuditLogger;
use App\Domain\Chatbots\Engine\BotInput;
use App\Domain\Chatbots\Engine\FlowEngine;
use App\Domain\Chatbots\Engine\FlowGraph;
use App\Domain\Chatbots\Engine\FlowSettings;
use App\Domain\Chatbots\Engine\TestRuntime;
use App\Domain\Chatbots\Models\ChatbotEvent;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Models\ChatbotFlowVersion;
use App\Domain\Chatbots\Models\ChatbotSecret;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Chatbots\Services\FlowTemplates;
use App\Domain\Crm\Models\ContactField;
use App\Domain\Crm\Models\ContactTag;
use App\Domain\Crm\Models\Segment;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use App\Infrastructure\Secrets\SecretStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Portal → Chatbots & Flows: build, test, publish and switch on a chatbot per WhatsApp number. */
final class ChatbotController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
        private readonly Chatbots $chatbots,
    ) {}

    public function index(): JsonResponse
    {
        $flows = ChatbotFlow::query()->with('phoneNumber')->orderBy('created_at')->get();
        $running = ChatbotSession::query()->whereIn('status', ChatbotSession::RUNNING)->selectRaw('flow_id, count(*) AS total')->groupBy('flow_id')->toBase()->pluck('total', 'flow_id');
        $started = ChatbotSession::query()->where('created_at', '>=', now()->subDays(30))->selectRaw('flow_id, count(*) AS total')->groupBy('flow_id')->toBase()->pluck('total', 'flow_id');

        return response()->json([
            'data' => $flows->map(fn (ChatbotFlow $f) => $this->present($f) + ['stats' => ['running' => (int) ($running[$f->id] ?? 0), 'started_30d' => (int) ($started[$f->id] ?? 0)]])->values(),
            'meta' => $this->allowance(),
        ]);
    }

    public function show(ChatbotFlow $flow): JsonResponse
    {
        return response()->json(['data' => $this->present($flow, full: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone_number_id' => ['nullable', 'uuid'],
            'template' => ['nullable', Rule::in(FlowTemplates::KEYS)],
        ]);
        $this->assertNumber($data['phone_number_id'] ?? null);

        $flow = $this->entitlements->withinLimit($this->context->tenant(), FeatureKey::Chatbots, 1, fn () => ChatbotFlow::query()->create([
            'name' => trim($data['name']),
            'phone_number_id' => $data['phone_number_id'] ?? null,
            'draft' => FlowGraph::normalize(FlowTemplates::graph($data['template'] ?? 'menu')),
            'settings' => FlowSettings::normalize(null),
            'draft_updated_at' => now(),
            'created_by_membership_id' => $this->context->membership()?->id,
        ]));
        $this->audit->record('chatbot.created', $flow);

        return response()->json(['data' => $this->present($flow, full: true)], 201);
    }

    /** Autosave from the builder: name, number, draft graph and settings. Returns the publish checks. */
    public function update(Request $request, ChatbotFlow $flow): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone_number_id' => ['sometimes', 'nullable', 'uuid'],
            'draft' => ['sometimes', 'array'],
            'draft.nodes' => ['sometimes', 'array', 'max:'.FlowGraph::MAX_NODES],
            'settings' => ['sometimes', 'array'],
        ]);

        if (array_key_exists('phone_number_id', $data) && $data['phone_number_id'] !== $flow->phone_number_id) {
            $this->assertNumber($data['phone_number_id']);
            if ($flow->is_active) {
                throw ValidationException::withMessages(['phone_number_id' => 'Switch this bot off before moving it to another number.']);
            }
            $flow->phone_number_id = $data['phone_number_id'];
        }
        if (isset($data['name'])) {
            $flow->name = trim($data['name']);
        }
        if (isset($data['draft'])) {
            $flow->draft = FlowGraph::normalize($data['draft']);
            $flow->draft_updated_at = now();
        }
        if (isset($data['settings'])) {
            $flow->settings = FlowSettings::normalize($data['settings']);
            $flow->draft_updated_at = now();
        }
        $flow->save();

        return response()->json(['data' => $this->present($flow, full: true)]);
    }

    /** The publish gate: refuses until every check passes, then snapshots the draft as a new version. */
    public function publish(ChatbotFlow $flow): JsonResponse
    {
        $issues = $this->issues($flow->draft);
        $errors = array_values(array_filter($issues, fn ($i) => $i['level'] === 'error'));
        if ($errors !== []) {
            return response()->json([
                'error' => ['code' => 'validation_failed', 'message' => 'Fix the highlighted problems before publishing.', 'details' => ['issues' => $issues]],
            ], 422);
        }

        DB::transaction(function () use ($flow): void {
            $version = (int) ChatbotFlowVersion::query()->where('flow_id', $flow->id)->max('version') + 1;
            ChatbotFlowVersion::query()->create([
                'flow_id' => $flow->id, 'version' => $version, 'graph' => FlowGraph::normalize($flow->draft),
                'settings' => FlowSettings::normalize($flow->settings), 'published_by_membership_id' => $this->context->membership()?->id,
            ]);
            $flow->forceFill(['published_version' => $version, 'published_at' => now(), 'draft_updated_at' => now()->subSecond()])->save();
        });
        $this->audit->record('chatbot.published', $flow, meta: ['version' => $flow->published_version]);

        return response()->json(['data' => $this->present($flow->refresh(), full: true)]);
    }

    /** Switch the bot on (or off) for its number. Only one bot is live per number. */
    public function activate(Request $request, ChatbotFlow $flow): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'replace' => ['sometimes', 'boolean']]);

        if (! $data['active']) {
            if ($flow->is_active) {
                $flow->forceFill(['is_active' => false])->save();
                $this->chatbots->stopFlow($flow, 'flow_disabled');
                $this->audit->record('chatbot.deactivated', $flow);
            }

            return response()->json(['data' => $this->present($flow, full: true)]);
        }

        $this->entitlements->ensureEnabled($this->context->tenant(), FeatureKey::Chatbots);
        if ($flow->published_version === null) {
            throw ValidationException::withMessages(['active' => 'Publish the flow before switching it on.']);
        }
        if ($flow->phone_number_id === null) {
            throw ValidationException::withMessages(['active' => 'Choose the WhatsApp number this bot answers on.']);
        }
        $number = PhoneNumber::query()->find($flow->phone_number_id);
        if ($number === null || $number->status !== PhoneNumberStatus::Connected) {
            throw ValidationException::withMessages(['active' => 'The WhatsApp number of this bot is not connected.']);
        }
        $other = ChatbotFlow::query()->where('phone_number_id', $flow->phone_number_id)->where('is_active', true)->whereKeyNot($flow->id)->first();
        if ($other !== null && ! ($data['replace'] ?? false)) {
            return response()->json(['error' => [
                'code' => 'conflict',
                'message' => "“{$other->name}” is live on this number. Switching this bot on turns that one off.",
                'details' => ['active_flow' => ['id' => $other->id, 'name' => $other->name]],
            ]], 409);
        }

        DB::transaction(function () use ($flow, $other): void {
            if ($other !== null) {
                $other->forceFill(['is_active' => false])->save();
            }
            $flow->forceFill(['is_active' => true])->save();
        });
        if ($other !== null) {
            $this->chatbots->stopFlow($other, 'flow_replaced');
        }
        $this->audit->record('chatbot.activated', $flow, meta: ['replaced' => $other?->id]);

        return response()->json(['data' => $this->present($flow->refresh(), full: true)]);
    }

    public function duplicate(ChatbotFlow $flow): JsonResponse
    {
        $copy = $this->entitlements->withinLimit($this->context->tenant(), FeatureKey::Chatbots, 1, fn () => ChatbotFlow::query()->create([
            'name' => mb_substr($flow->name.' (copy)', 0, 120),
            'phone_number_id' => $flow->phone_number_id,
            'draft' => $flow->draft,
            'settings' => $flow->settings,
            'draft_updated_at' => now(),
            'created_by_membership_id' => $this->context->membership()?->id,
        ]));
        $this->audit->record('chatbot.created', $copy, meta: ['copied_from' => $flow->id]);

        return response()->json(['data' => $this->present($copy, full: true)], 201);
    }

    public function destroy(ChatbotFlow $flow): Response
    {
        $this->chatbots->stopFlow($flow, 'flow_deleted');
        $this->audit->record('chatbot.deleted', null, meta: ['flow_id' => $flow->id, 'name' => $flow->name]);
        $flow->delete();

        return response()->noContent();
    }

    /** Rollback: a published version becomes the draft again, and (by default) live again. */
    public function restore(Request $request, ChatbotFlow $flow, int $version): JsonResponse
    {
        $row = ChatbotFlowVersion::query()->where('flow_id', $flow->id)->where('version', $version)->firstOrFail();
        $flow->forceFill(['draft' => $row->graph, 'settings' => $row->settings, 'draft_updated_at' => now()])->save();
        $this->audit->record('chatbot.version_restored', $flow, meta: ['version' => $version]);

        return $request->boolean('publish', true) ? $this->publish($flow) : response()->json(['data' => $this->present($flow, full: true)]);
    }

    /** The funnel on the canvas: per block entries, the branches taken, and where customers left. */
    public function analytics(Request $request, ChatbotFlow $flow): JsonResponse
    {
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:90']])['days'] ?? 30);
        $since = now()->subDays($days);

        $rows = ChatbotEvent::query()->where('flow_id', $flow->id)->where('created_at', '>=', $since)
            ->selectRaw('node_id, kind, detail, count(*) AS total, count(DISTINCT session_id) AS sessions')
            ->groupBy('node_id', 'kind', 'detail')->toBase()->get();
        $nodes = [];
        foreach ($rows as $r) {
            $n = &$nodes[$r->node_id];
            $n ??= ['entries' => 0, 'clicks' => [], 'abandoned' => 0, 'handoffs' => 0, 'completed' => 0, 'errors' => 0];
            match ($r->kind) {
                'enter' => $n['entries'] += (int) $r->sessions,
                'click' => $n['clicks'][(string) $r->detail] = ($n['clicks'][(string) $r->detail] ?? 0) + (int) $r->total,
                'abandon' => $n['abandoned'] += (int) $r->total,
                'handoff' => $n['handoffs'] += (int) $r->total,
                'complete' => $n['completed'] += (int) $r->total,
                default => $n['errors'] += (int) $r->total,
            };
            unset($n);
        }

        $sessions = ChatbotSession::query()->where('flow_id', $flow->id)->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) AS total')->groupBy('status')->toBase()->pluck('total', 'status');
        $total = (int) $sessions->sum();

        return response()->json(['data' => [
            'days' => $days,
            'sessions' => [
                'started' => $total,
                'running' => (int) (($sessions['active'] ?? 0) + ($sessions['waiting'] ?? 0)),
                'completed' => (int) ($sessions['completed'] ?? 0),
                'handed_off' => (int) ($sessions['handed_off'] ?? 0),
                'abandoned' => (int) ($sessions['abandoned'] ?? 0),
                'stopped' => (int) ($sessions['stopped'] ?? 0),
                'completion_rate' => $total > 0 ? round(100 * ((int) ($sessions['completed'] ?? 0) + (int) ($sessions['handed_off'] ?? 0)) / $total, 1) : null,
            ],
            'nodes' => (object) $nodes,
        ]]);
    }

    /**
     * Test chat: the draft runs on the real engine, with the data of a chosen contact if any.
     * Nothing is sent on WhatsApp and nothing is saved on the contact; API-call blocks do call out.
     */
    public function test(Request $request, ChatbotFlow $flow, FlowEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'test_id' => ['nullable', 'string', 'max:40'],
            'contact_id' => ['nullable', 'uuid'],
            'text' => ['nullable', 'string', 'max:1000'],
            'payload' => ['nullable', 'string', 'max:300'],
        ]);
        $graph = FlowGraph::from($flow->draft);
        $settings = FlowSettings::normalize($flow->settings);
        $key = fn (string $id) => "chatbot-test:{$this->context->id()}:{$flow->id}:{$id}";

        $saved = isset($data['test_id']) ? Cache::get($key($data['test_id'])) : null;
        $testId = is_array($saved) ? (string) $data['test_id'] : Str::random(24);
        $contact = is_array($saved) ? $saved['contact'] : TestRuntime::contactFor(isset($data['contact_id']) ? Contact::query()->find($data['contact_id']) : null);
        $rt = new TestRuntime(is_array($saved) ? $saved['state'] : [], $contact);
        $input = new BotInput($data['text'] ?? null, $data['payload'] ?? null);

        if (! is_array($saved)) {
            if ($graph->start() === null) {
                throw ValidationException::withMessages(['flow' => 'Add a Start block first.']);
            }
            $engine->begin($graph, $settings, $rt);
        } elseif (in_array($rt->state['status'], ChatbotSession::RUNNING, true)) {
            if ($rt->state['status'] === 'waiting') {
                // No one waits minutes in a test: continue after the Delay straight away.
                $engine->resume($graph, $settings, $rt);
            } else {
                $engine->inbound($graph, $settings, $rt, $input);
            }
        } else {
            $rt->note('The flow has ended. Start again to test once more.');
        }

        Cache::put($key($testId), ['state' => $rt->state, 'contact' => $rt->contact], now()->addHours(2));

        return response()->json(['data' => [
            'test_id' => $testId,
            'transcript' => $rt->transcript,
            'state' => [
                'status' => $rt->state['status'],
                'node_id' => $rt->state['current_node'],
                'awaiting_input' => $rt->state['awaiting_input'],
                'vars' => (object) array_filter($rt->state['vars'], fn ($k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY),
                'contact' => $rt->contact,
            ],
        ]]);
    }

    /** Everything the builder's pickers need, in one call (fields, tags, segments, teammates, templates, numbers). */
    public function options(): JsonResponse
    {
        $numbers = PhoneNumber::query()->orderBy('created_at')->get();
        $wabas = $numbers->pluck('waba_account_id')->unique()->values();

        return response()->json(['data' => [
            'numbers' => $numbers->map(fn (PhoneNumber $n) => [
                'id' => $n->id, 'display_phone_number' => $n->display_phone_number, 'verified_name' => $n->verified_name, 'status' => $n->status->value,
                'waba_account_id' => $n->waba_account_id,
            ])->values(),
            'fields' => ContactField::query()->orderBy('created_at')->get(['key', 'label', 'type'])->values(),
            'tags' => ContactTag::query()->orderBy('name')->pluck('name')->values(),
            'segments' => Segment::query()->orderBy('name')->get(['id', 'name'])->values(),
            'members' => TenantMembership::query()->active()->with('user:id,name')->get()->map(fn (TenantMembership $m) => [
                'id' => $m->id, 'name' => $m->user->name ?? 'Teammate',
            ])->values(),
            'templates' => MessageTemplate::query()->whereIn('waba_account_id', $wabas)->get()->filter(fn (MessageTemplate $t) => $t->isSendable())
                ->map(fn (MessageTemplate $t) => [
                    'name' => $t->name, 'language' => $t->language, 'category' => $t->category, 'waba_account_id' => $t->waba_account_id,
                    'variables' => array_intersect_key($t->variables(), ['header' => true, 'body' => true, 'header_format' => true]),
                ])->values(),
            'secrets' => ChatbotSecret::query()->orderBy('name')->pluck('name')->values(),
        ]]);
    }

    // ── API keys for API-call blocks ─────────────────────────────────────────────────────────

    public function secrets(): JsonResponse
    {
        return response()->json(['data' => ChatbotSecret::query()->orderBy('name')->get()->map(fn (ChatbotSecret $s) => [
            'name' => $s->name, 'hint' => $s->hint !== null ? '…'.$s->hint : null, 'updated_at' => $s->updated_at?->toIso8601String(),
        ])->values()]);
    }

    public function saveSecret(Request $request, SecretStore $store): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_]{0,59}$/'],
            'value' => ['required', 'string', 'max:4000'],
        ], ['name.regex' => 'Use letters, numbers and _ only, starting with a letter (e.g. WOO_KEY).']);
        $existing = ChatbotSecret::query()->where('name', $data['name'])->first();
        if ($existing === null && ChatbotSecret::query()->count() >= 50) {
            throw ValidationException::withMessages(['name' => 'A workspace can keep up to 50 API keys.']);
        }
        $hint = mb_strlen($data['value']) >= 12 ? mb_substr($data['value'], -4) : null;
        if ($existing !== null) {
            $store->replace($existing->secret_id, $data['value']);
            $existing->forceFill(['hint' => $hint])->save();
        } else {
            ChatbotSecret::query()->create(['name' => $data['name'], 'secret_id' => $store->put('chatbot.secret', $data['value'], $this->context->id()), 'hint' => $hint]);
        }
        $this->audit->record('chatbot.secret_saved', null, meta: ['name' => $data['name']]);

        return $this->secrets();
    }

    public function deleteSecret(string $name, SecretStore $store): Response
    {
        $row = ChatbotSecret::query()->where('name', $name)->firstOrFail();
        $store->destroy($row->secret_id);
        $row->delete();
        $this->audit->record('chatbot.secret_deleted', null, meta: ['name' => $name]);

        return response()->noContent();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function present(ChatbotFlow $flow, bool $full = false): array
    {
        $number = $flow->phone_number_id !== null ? ($flow->relationLoaded('phoneNumber') ? $flow->phoneNumber : $flow->phoneNumber()->first()) : null;
        $out = [
            'id' => $flow->id,
            'name' => $flow->name,
            'phone_number_id' => $flow->phone_number_id,
            'phone_number' => $number !== null ? [
                'id' => $number->id, 'display_phone_number' => $number->display_phone_number, 'verified_name' => $number->verified_name, 'status' => $number->status->value,
            ] : null,
            'is_active' => $flow->is_active,
            'status' => $flow->is_active ? 'live' : ($flow->published_version !== null ? 'paused' : 'draft'),
            'published_version' => $flow->published_version,
            'published_at' => $flow->published_at?->toIso8601String(),
            'has_unpublished_changes' => $flow->hasUnpublishedChanges(),
            'draft_updated_at' => $flow->draft_updated_at?->toIso8601String(),
            'created_at' => $flow->created_at?->toIso8601String(),
            'updated_at' => $flow->updated_at?->toIso8601String(),
        ];
        if ($full) {
            $out += [
                'draft' => FlowGraph::normalize($flow->draft),
                'settings' => FlowSettings::normalize($flow->settings),
                'issues' => $this->issues($flow->draft),
                'versions' => ChatbotFlowVersion::query()->where('flow_id', $flow->id)->orderByDesc('version')->limit(20)->get()
                    ->map(fn (ChatbotFlowVersion $v) => ['version' => $v->version, 'created_at' => $v->created_at?->toIso8601String(), 'published_by_membership_id' => $v->published_by_membership_id])->values(),
                'can' => ['manage' => $this->context->can(Permission::ChatbotsManage)],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $draft
     * @return list<array{level: string, node_id: ?string, message: string}>
     */
    private function issues(?array $draft): array
    {
        /** @var array<string, true> $fields */
        $fields = array_fill_keys(ContactField::query()->pluck('key')->all(), true);

        return FlowGraph::from($draft)->issues($fields === [] ? ['__none__' => true] : $fields);
    }

    /** @return array<string, mixed> */
    private function allowance(): array
    {
        $tenant = $this->context->tenant();
        $entitlement = $this->entitlements->for($tenant)->get(FeatureKey::Chatbots);

        return ['enabled' => $entitlement->enabled, 'limit' => $entitlement->limit, 'used' => ChatbotFlow::query()->count()];
    }

    private function assertNumber(?string $id): void
    {
        if ($id !== null && ! PhoneNumber::query()->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['phone_number_id' => 'Choose one of your WhatsApp numbers.']);
        }
    }
}
