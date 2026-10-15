<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permission;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Chatbots\Engine\BotInput;
use App\Domain\Chatbots\Engine\FlowChecks;
use App\Domain\Chatbots\Engine\FlowEngine;
use App\Domain\Chatbots\Engine\FlowGraph;
use App\Domain\Chatbots\Engine\FlowSettings;
use App\Domain\Chatbots\Engine\TestRuntime;
use App\Domain\Chatbots\Models\ChatbotEvent;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Models\ChatbotFlowVersion;
use App\Domain\Chatbots\Models\ChatbotNodeComment;
use App\Domain\Chatbots\Models\ChatbotSecret;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Chatbots\Models\ChatbotSnippet;
use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Chatbots\Services\ChatbotWorkspace;
use App\Domain\Chatbots\Services\DataTriggers;
use App\Domain\Chatbots\Services\FlowTemplates;
use App\Domain\Chatbots\Services\OrderLookup;
use App\Domain\Crm\Models\ContactField;
use App\Domain\Crm\Models\ContactTag;
use App\Domain\Crm\Models\Segment;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Media;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use App\Infrastructure\Secrets\SecretStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Portal → Chatbots & Flows: build, test, review, publish and switch on chatbots per WhatsApp number.
 *
 * Roles: chatbots.view (viewer) sees flows, tests them and reads analytics; chatbots.manage
 * (editor) edits drafts; chatbots.publish (publisher) publishes, switches flows on and off,
 * schedules them and approves reviews. A flow can be limited to some editors (flow.editors).
 */
final class ChatbotController extends Controller
{
    public const EXPORT_FORMAT = '10x-engage-chatbot';

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
        private readonly Chatbots $chatbots,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'folder' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::in(['all', 'live', 'paused', 'draft', 'archived'])],
            'kind' => ['nullable', Rule::in(ChatbotFlow::KINDS)],
        ]);
        $status = $filters['status'] ?? 'all';
        $flows = ChatbotFlow::query()->with('phoneNumber')
            ->when(($filters['q'] ?? '') !== '', fn (Builder $q) => $q->where('name', 'ilike', '%'.addcslashes((string) $filters['q'], '%_\\').'%'))
            ->when(($filters['folder'] ?? '') !== '', fn (Builder $q) => $q->where('folder', $filters['folder']))
            ->when(isset($filters['kind']), fn (Builder $q) => $q->where('kind', $filters['kind']))
            ->when($status === 'archived', fn (Builder $q) => $q->whereNotNull('archived_at'), fn (Builder $q) => $q->whereNull('archived_at'))
            ->when($status === 'live', fn (Builder $q) => $q->where('is_active', true))
            ->when($status === 'paused', fn (Builder $q) => $q->where('is_active', false)->whereNotNull('published_version'))
            ->when($status === 'draft', fn (Builder $q) => $q->whereNull('published_version'))
            ->orderBy('folder')->orderBy('created_at')->get();

        $ids = $flows->pluck('id');
        $running = ChatbotSession::query()->whereIn('flow_id', $ids)->whereIn('status', ChatbotSession::RUNNING)->selectRaw('flow_id, count(*) AS total')->groupBy('flow_id')->toBase()->pluck('total', 'flow_id');
        $since = now()->subDays(30);
        $stats = ChatbotSession::query()->whereIn('flow_id', $ids)->where('created_at', '>=', $since)
            ->selectRaw("flow_id, count(*) AS started, count(*) FILTER (WHERE status IN ('completed', 'handed_off')) AS finished, count(*) FILTER (WHERE status = 'handed_off') AS handoffs")
            ->groupBy('flow_id')->toBase()->get()->keyBy('flow_id');

        return response()->json([
            'data' => $flows->map(function (ChatbotFlow $f) use ($running, $stats) {
                $s = $stats[$f->id] ?? null;
                $started = (int) ($s->started ?? 0);

                return $this->present($f) + ['attention' => $this->attention($f), 'stats' => [
                    'running' => (int) ($running[$f->id] ?? 0),
                    'started_30d' => $started,
                    'completion_rate' => $started > 0 ? round(100 * (int) $s->finished / $started, 1) : null,
                    'handoff_rate' => $started > 0 ? round(100 * (int) $s->handoffs / $started, 1) : null,
                ]];
            })->values(),
            'meta' => $this->allowance() + [
                'folders' => ChatbotFlow::query()->whereNotNull('folder')->distinct()->orderBy('folder')->pluck('folder')->values(),
                'approvals' => ChatbotWorkspace::approvals($this->context->tenant()),
                'can' => $this->abilities(),
            ],
        ]);
    }

    public function show(ChatbotFlow $flow): JsonResponse
    {
        return response()->json(['data' => $this->present($flow, full: true)]);
    }

    /** The template gallery: every template with its short form. */
    public function templates(): JsonResponse
    {
        return response()->json(['data' => FlowTemplates::catalog(), 'meta' => ['variables' => ChatbotWorkspace::variables($this->context->tenant())]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone_number_id' => ['nullable', 'uuid'],
            'template' => ['nullable', Rule::in(FlowTemplates::KEYS)],
            'config' => ['nullable', 'array'],
            'kind' => ['nullable', Rule::in(ChatbotFlow::KINDS)],
            'folder' => ['nullable', 'string', 'max:60'],
        ]);
        $this->assertNumber($data['phone_number_id'] ?? null);
        $kind = $data['kind'] ?? 'bot';
        $config = (array) ($data['config'] ?? []);
        $draft = $kind === 'subflow' ? self::subflowDraft() : FlowTemplates::graph($data['template'] ?? 'blank', $config);

        $flow = $this->entitlements->withinLimit($this->context->tenant(), FeatureKey::Chatbots, 1, fn () => ChatbotFlow::query()->create([
            'name' => trim($data['name']),
            'kind' => $kind,
            'folder' => isset($data['folder']) ? trim($data['folder']) : null,
            'phone_number_id' => $kind === 'bot' ? ($data['phone_number_id'] ?? null) : null,
            'draft' => FlowGraph::normalize($draft),
            'settings' => FlowSettings::normalize(null),
            'draft_updated_at' => now(),
            'created_by_membership_id' => $this->context->membership()?->id,
        ]));

        // The template form's business details become the workspace's business variables (filled
        // values replace old ones; defaults only fill what is still empty).
        if ($kind === 'bot' && isset($data['template'])) {
            $tenant = $this->context->tenant();
            $current = ChatbotWorkspace::variables($tenant);
            $merged = $current;
            foreach (FlowTemplates::business($data['template'], $config) as $key => $value) {
                $given = is_scalar($config[$key] ?? null) && trim((string) $config[$key]) !== '';
                if ($given || ! isset($current[$key]) || $current[$key] === '') {
                    $merged[$key] = $value;
                }
            }
            if ($merged !== $current) {
                ChatbotWorkspace::save($tenant, ['variables' => ChatbotWorkspace::cleanVariables($merged)]);
            }
        }
        $this->audit->record('chatbot.created', $flow, meta: ['template' => $data['template'] ?? null, 'kind' => $kind]);

        return response()->json(['data' => $this->present($flow, full: true)], 201);
    }

    /** Autosave from the builder (and the list page): name, number, draft, settings, folder, editors, schedule. */
    public function update(Request $request, ChatbotFlow $flow): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone_number_id' => ['sometimes', 'nullable', 'uuid'],
            'draft' => ['sometimes', 'array'],
            'draft.nodes' => ['sometimes', 'array', 'max:'.FlowGraph::MAX_NODES],
            'settings' => ['sometimes', 'array'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:60'],
            'editors' => ['sometimes', 'nullable', 'array', 'max:50'],
            'editors.*' => ['uuid'],
            'activate_at' => ['sometimes', 'nullable', 'date'],
            'deactivate_at' => ['sometimes', 'nullable', 'date'],
        ]);
        $this->assertCanEdit($flow);

        if (array_key_exists('phone_number_id', $data) && $data['phone_number_id'] !== $flow->phone_number_id) {
            $this->assertNumber($data['phone_number_id']);
            if ($flow->is_active) {
                throw ValidationException::withMessages(['phone_number_id' => 'Switch this bot off before moving it to another number.']);
            }
            $flow->phone_number_id = $flow->kind === 'bot' ? $data['phone_number_id'] : null;
        }
        if (isset($data['name'])) {
            $flow->name = trim($data['name']);
        }
        if (array_key_exists('folder', $data)) {
            $flow->folder = $data['folder'] !== null && trim($data['folder']) !== '' ? trim($data['folder']) : null;
        }
        if (isset($data['draft'])) {
            $flow->draft = FlowGraph::normalize($data['draft']);
            $flow->draft_updated_at = now();
        }
        if (isset($data['settings'])) {
            $flow->settings = FlowSettings::normalize($data['settings']);
            $flow->draft_updated_at = now();
        }
        if (array_key_exists('editors', $data)) {
            $this->context->authorize(Permission::ChatbotsPublish);
            $ids = $data['editors'] === null ? null : TenantMembership::query()->active()->whereIn('id', $data['editors'])->pluck('id')->values()->all();
            $flow->editors = $ids === [] ? null : $ids;
        }
        foreach (['activate_at', 'deactivate_at'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->context->authorize(Permission::ChatbotsPublish);
                $at = $data[$key] !== null ? Carbon::parse($data[$key]) : null;
                if ($at !== null && $at->isPast()) {
                    throw ValidationException::withMessages([$key => 'Choose a time in the future.']);
                }
                $flow->{$key} = $at;
            }
        }
        if ($flow->activate_at !== null && $flow->deactivate_at !== null && $flow->deactivate_at->lte($flow->activate_at)) {
            throw ValidationException::withMessages(['deactivate_at' => 'The switch-off time must be after the switch-on time.']);
        }
        $scheduled = $flow->isDirty(['activate_at', 'deactivate_at']);
        $flow->save();
        if ($scheduled) {
            $this->audit->record('chatbot.scheduled', $flow, meta: ['activate_at' => $flow->activate_at?->toIso8601String(), 'deactivate_at' => $flow->deactivate_at?->toIso8601String()]);
        }

        return response()->json(['data' => $this->present($flow, full: true)]);
    }

    /** The publish gate: refuses until every check passes (and, in agency mode, a reviewer approved this draft). */
    public function publish(ChatbotFlow $flow): JsonResponse
    {
        $issues = $this->issues($flow);
        if (array_filter($issues, fn ($i) => $i['level'] === 'error') !== []) {
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
        DataTriggers::forget($this->context->id());
        $this->audit->record('chatbot.published', $flow, meta: ['version' => $flow->published_version]);

        return response()->json(['data' => $this->present($flow->refresh(), full: true)]);
    }

    /**
     * Switch a flow on (or off). Several flows can be live on one number; only one may be the
     * default ("any message") flow and one the greeting flow — `replace` switches the other one off.
     */
    public function activate(Request $request, ChatbotFlow $flow): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'replace' => ['sometimes', 'boolean']]);

        if (! $data['active']) {
            if ($flow->is_active) {
                $flow->forceFill(['is_active' => false])->save();
                $this->chatbots->stopFlow($flow, 'flow_disabled');
                DataTriggers::forget($this->context->id());
                $this->audit->record('chatbot.deactivated', $flow);
            }

            return response()->json(['data' => $this->present($flow, full: true)]);
        }

        $this->entitlements->ensureEnabled($this->context->tenant(), FeatureKey::Chatbots);
        $conflict = $this->chatbots->conflict($flow);
        $problem = $this->chatbots->activationProblem($flow, ignoreConflict: true);
        if ($problem !== null) {
            throw ValidationException::withMessages(['active' => $problem]);
        }
        if ($conflict !== null && ! ($data['replace'] ?? false)) {
            return response()->json(['error' => [
                'code' => 'conflict',
                'message' => "“{$conflict[0]->name}” is the {$conflict[1]} flow on this number. Switching this flow on turns that one off.",
                'details' => ['active_flow' => ['id' => $conflict[0]->id, 'name' => $conflict[0]->name]],
            ]], 409);
        }

        $other = $conflict[0] ?? null;
        DB::transaction(function () use ($flow, $other): void {
            $other?->forceFill(['is_active' => false])->save();
            $flow->forceFill(['is_active' => true, 'activate_at' => null])->save();
        });
        if ($other !== null) {
            $this->chatbots->stopFlow($other, 'flow_replaced');
        }
        DataTriggers::forget($this->context->id());
        $this->audit->record('chatbot.activated', $flow, meta: ['replaced' => $other?->id]);

        return response()->json(['data' => $this->present($flow->refresh(), full: true)]);
    }

    public function archive(Request $request, ChatbotFlow $flow): JsonResponse
    {
        $archived = (bool) $request->validate(['archived' => ['required', 'boolean']])['archived'];
        if ($archived && $flow->archived_at === null) {
            $wasActive = $flow->is_active;
            $flow->forceFill(['archived_at' => now(), 'is_active' => false, 'activate_at' => null, 'deactivate_at' => null])->save();
            if ($wasActive) {
                $this->chatbots->stopFlow($flow, 'flow_disabled');
            }
            $this->audit->record('chatbot.archived', $flow);
        } elseif (! $archived && $flow->archived_at !== null) {
            $flow->forceFill(['archived_at' => null])->save();
            $this->audit->record('chatbot.unarchived', $flow);
        }
        DataTriggers::forget($this->context->id());

        return response()->json(['data' => $this->present($flow, full: true)]);
    }

    public function duplicate(ChatbotFlow $flow): JsonResponse
    {
        $copy = $this->entitlements->withinLimit($this->context->tenant(), FeatureKey::Chatbots, 1, fn () => ChatbotFlow::query()->create([
            'name' => mb_substr($flow->name.' (copy)', 0, 120),
            'kind' => $flow->kind,
            'folder' => $flow->folder,
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
        $this->assertCanEdit($flow);
        $this->chatbots->stopFlow($flow, 'flow_deleted');
        $this->audit->record('chatbot.deleted', null, meta: ['flow_id' => $flow->id, 'name' => $flow->name]);
        $flow->delete();
        DataTriggers::forget($this->context->id());

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

    // ── Export / import ──────────────────────────────────────────────────────────────────────

    public function export(ChatbotFlow $flow): JsonResponse
    {
        $this->audit->record('chatbot.exported', $flow);

        return response()->json([
            'format' => self::EXPORT_FORMAT, 'version' => 1, 'exported_at' => now()->toIso8601String(),
            'flow' => ['name' => $flow->name, 'kind' => $flow->kind, 'folder' => $flow->folder, 'draft' => FlowGraph::normalize($flow->draft), 'settings' => FlowSettings::normalize($flow->settings)],
        ], 200, ['Content-Disposition' => 'attachment; filename="'.Str::slug($flow->name ?: 'chatbot').'.json"']);
    }

    /** A flow exported from any workspace becomes a new draft here (API keys, templates and fields are matched by name). */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'format' => ['required', Rule::in([self::EXPORT_FORMAT])],
            'flow' => ['required', 'array'],
            'flow.name' => ['required', 'string', 'max:120'],
            'flow.kind' => ['nullable', Rule::in(ChatbotFlow::KINDS)],
            'flow.folder' => ['nullable', 'string', 'max:60'],
            'flow.draft' => ['required', 'array'],
            'flow.draft.nodes' => ['required', 'array', 'max:'.FlowGraph::MAX_NODES],
            'flow.settings' => ['nullable', 'array'],
            'phone_number_id' => ['nullable', 'uuid'],
        ], ['format.in' => 'This is not a chatbot export file.']);
        $this->assertNumber($data['phone_number_id'] ?? null);
        $kind = $data['flow']['kind'] ?? 'bot';

        $flow = $this->entitlements->withinLimit($this->context->tenant(), FeatureKey::Chatbots, 1, fn () => ChatbotFlow::query()->create([
            'name' => trim($data['flow']['name']),
            'kind' => $kind,
            'folder' => $data['flow']['folder'] ?? null,
            'phone_number_id' => $kind === 'bot' ? ($data['phone_number_id'] ?? null) : null,
            'draft' => FlowGraph::normalize($data['flow']['draft']),
            'settings' => FlowSettings::normalize($data['flow']['settings'] ?? null),
            'draft_updated_at' => now(),
            'created_by_membership_id' => $this->context->membership()?->id,
        ]));
        $this->audit->record('chatbot.imported', $flow);

        return response()->json(['data' => $this->present($flow, full: true)], 201);
    }

    // ── Review / approval (agency mode) ──────────────────────────────────────────────────────

    public function review(Request $request, ChatbotFlow $flow): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['request', 'approve', 'changes', 'cancel'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $me = $this->context->membership()?->id;
        if (in_array($data['action'], ['approve', 'changes'], true)) {
            $this->context->authorize(Permission::ChatbotsPublish);
            if ($flow->review_status !== 'requested' && $data['action'] === 'changes') {
                throw ValidationException::withMessages(['action' => 'No review was requested for this flow.']);
            }
            $flow->forceFill([
                'review_status' => $data['action'] === 'approve' ? 'approved' : 'changes_requested',
                'review_hash' => $data['action'] === 'approve' ? $flow->draftHash() : null,
                'reviewed_by_membership_id' => $me, 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null,
            ])->save();
        } elseif ($data['action'] === 'request') {
            $this->context->authorize(Permission::ChatbotsManage);
            $flow->forceFill(['review_status' => 'requested', 'review_hash' => null, 'review_note' => $data['note'] ?? null, 'reviewed_by_membership_id' => null, 'reviewed_at' => null])->save();
        } else {
            $this->context->authorize(Permission::ChatbotsManage);
            $flow->forceFill(['review_status' => null, 'review_hash' => null, 'review_note' => null])->save();
        }
        $this->audit->record('chatbot.review_'.$data['action'], $flow, meta: ['note' => $data['note'] ?? null]);

        return response()->json(['data' => $this->present($flow, full: true)]);
    }

    // ── Comments on blocks ───────────────────────────────────────────────────────────────────

    public function comments(ChatbotFlow $flow): JsonResponse
    {
        $rows = ChatbotNodeComment::query()->where('flow_id', $flow->id)->orderBy('created_at')->limit(500)->get();
        $names = $this->memberNames($rows->pluck('membership_id')->merge($rows->pluck('resolved_by_membership_id'))->filter()->unique()->values()->all());
        $me = $this->context->membership()?->id;

        return response()->json(['data' => $rows->map(fn (ChatbotNodeComment $c) => [
            'id' => $c->id, 'node_id' => $c->node_id, 'body' => $c->body,
            'author' => $c->membership_id !== null ? ($names[$c->membership_id] ?? 'Former member') : 'Someone',
            'mine' => $c->membership_id === $me,
            'resolved_at' => $c->resolved_at?->toIso8601String(),
            'resolved_by' => $c->resolved_by_membership_id !== null ? ($names[$c->resolved_by_membership_id] ?? null) : null,
            'created_at' => $c->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function addComment(Request $request, ChatbotFlow $flow): JsonResponse
    {
        $data = $request->validate(['node_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'], 'body' => ['required', 'string', 'max:2000']]);
        ChatbotNodeComment::query()->create(['flow_id' => $flow->id, 'node_id' => $data['node_id'], 'body' => trim($data['body']), 'membership_id' => $this->context->membership()?->id]);

        return $this->comments($flow);
    }

    public function updateComment(Request $request, ChatbotFlow $flow, string $comment): JsonResponse
    {
        $resolved = (bool) $request->validate(['resolved' => ['required', 'boolean']])['resolved'];
        $row = ChatbotNodeComment::query()->where('flow_id', $flow->id)->whereKey($comment)->firstOrFail();
        $row->forceFill(['resolved_at' => $resolved ? now() : null, 'resolved_by_membership_id' => $resolved ? $this->context->membership()?->id : null])->save();

        return $this->comments($flow);
    }

    public function deleteComment(ChatbotFlow $flow, string $comment): JsonResponse
    {
        $row = ChatbotNodeComment::query()->where('flow_id', $flow->id)->whereKey($comment)->firstOrFail();
        if ($row->membership_id !== $this->context->membership()?->id) {
            $this->context->authorize(Permission::ChatbotsPublish);
        }
        $row->delete();

        return $this->comments($flow);
    }

    // ── History (audit log of this flow) ─────────────────────────────────────────────────────

    public function history(ChatbotFlow $flow): JsonResponse
    {
        $rows = AuditLog::query()->where('entity_type', 'chatbot_flow')->where('entity_id', $flow->id)->latest('created_at')->limit(100)->get();
        $users = User::query()->whereIn('id', $rows->where('actor_type', 'user')->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        return response()->json(['data' => $rows->map(fn (AuditLog $r) => [
            'action' => $r->getAttribute('action'),
            'actor' => match ($r->getAttribute('actor_type')) {
                'user' => $users[$r->getAttribute('actor_id')] ?? 'Former member',
                'admin' => '10X support',
                default => 'System',
            },
            'meta' => $r->getAttribute('meta'),
            'created_at' => Carbon::parse($r->getAttribute('created_at'))->toIso8601String(),
        ])->values()]);
    }

    // ── Analytics ────────────────────────────────────────────────────────────────────────────

    /** The flow dashboard and the numbers on the canvas: per block entries, branches taken, drop-off; completion, time and handoff rate; A/B results. */
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
            $n ??= ['entries' => 0, 'clicks' => [], 'abandoned' => 0, 'handoffs' => 0, 'completed' => 0, 'errors' => 0, 'no_match' => 0];
            match ($r->kind) {
                'enter' => $n['entries'] += (int) $r->sessions,
                'click' => $n['clicks'][(string) $r->detail] = ($n['clicks'][(string) $r->detail] ?? 0) + (int) $r->total,
                'abandon' => $n['abandoned'] += (int) $r->total,
                'handoff' => $n['handoffs'] += (int) $r->total,
                'complete' => $n['completed'] += (int) $r->total,
                default => $r->detail === 'no_match' ? $n['no_match'] += (int) $r->total : $n['errors'] += (int) $r->total,
            };
            unset($n);
        }

        $sessions = ChatbotSession::query()->where('flow_id', $flow->id)->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) AS total')->groupBy('status')->toBase()->pluck('total', 'status');
        $total = (int) $sessions->sum();
        $finished = (int) ($sessions['completed'] ?? 0) + (int) ($sessions['handed_off'] ?? 0);
        $avg = ChatbotSession::query()->where('flow_id', $flow->id)->where('created_at', '>=', $since)->whereIn('status', ['completed', 'handed_off'])->whereNotNull('ended_at')
            ->selectRaw('avg(extract(epoch from (ended_at - created_at))) AS seconds')->toBase()->value('seconds');
        $daily = ChatbotSession::query()->where('flow_id', $flow->id)->where('created_at', '>=', $since)
            ->selectRaw("to_char(created_at, 'YYYY-MM-DD') AS day, count(*) AS started, count(*) FILTER (WHERE status IN ('completed', 'handed_off')) AS finished")
            ->groupBy('day')->orderBy('day')->toBase()->get();

        // Where customers stopped: the blocks with the most abandoned sessions.
        $graph = FlowGraph::from($flow->publishedGraph()->graph ?? $flow->draft);
        $dropoff = collect($nodes)->filter(fn ($n) => $n['abandoned'] > 0)->sortByDesc('abandoned')->take(8)
            ->map(fn ($n, $id) => ['node_id' => (string) $id, 'title' => $graph->node((string) $id)['title'] ?? (string) $id, 'abandoned' => $n['abandoned'], 'entries' => $n['entries']])->values();

        return response()->json(['data' => [
            'days' => $days,
            'sessions' => [
                'started' => $total,
                'running' => (int) (($sessions['active'] ?? 0) + ($sessions['waiting'] ?? 0)),
                'completed' => (int) ($sessions['completed'] ?? 0),
                'handed_off' => (int) ($sessions['handed_off'] ?? 0),
                'abandoned' => (int) ($sessions['abandoned'] ?? 0),
                'stopped' => (int) ($sessions['stopped'] ?? 0),
                'completion_rate' => $total > 0 ? round(100 * $finished / $total, 1) : null,
                'handoff_rate' => $total > 0 ? round(100 * (int) ($sessions['handed_off'] ?? 0) / $total, 1) : null,
                'avg_seconds' => $avg !== null ? (int) round((float) $avg) : null,
            ],
            'daily' => $daily->map(fn ($d) => ['day' => $d->day, 'started' => (int) $d->started, 'finished' => (int) $d->finished])->values(),
            'dropoff' => $dropoff,
            'ab' => $this->abResults($flow, $graph, $since),
            'nodes' => (object) $nodes,
        ]]);
    }

    /** @return list<array<string, mixed>> */
    private function abResults(ChatbotFlow $flow, FlowGraph $graph, Carbon $since): array
    {
        $out = [];
        foreach ($graph->nodes() as $id => $node) {
            if ($node['type'] !== 'split') {
                continue;
            }
            $goal = $node['config']['goal'] ?? null;
            $query = DB::table('chatbot_events AS e')
                ->where('e.flow_id', $flow->id)->where('e.node_id', $id)->where('e.kind', 'click')->where('e.created_at', '>=', $since)
                ->where('e.tenant_id', $this->context->id());
            if ($goal !== null) {
                $query->leftJoin('chatbot_events AS g', fn ($j) => $j->on('g.session_id', '=', 'e.session_id')->where('g.node_id', '=', $goal)->where('g.kind', '=', 'enter'))
                    ->selectRaw('e.detail AS branch, count(DISTINCT e.session_id) AS sessions, count(DISTINCT g.session_id) AS goals');
            } else {
                $query->leftJoin('chatbot_sessions AS s', fn ($j) => $j->on('s.id', '=', 'e.session_id')->whereIn('s.status', ['completed', 'handed_off']))
                    ->selectRaw('e.detail AS branch, count(DISTINCT e.session_id) AS sessions, count(DISTINCT s.id) AS goals');
            }
            $rows = $query->groupBy('e.detail')->get()->keyBy('branch');
            $variant = fn (string $b) => [
                'sessions' => (int) ($rows[$b]->sessions ?? 0),
                'goals' => (int) ($rows[$b]->goals ?? 0),
                'rate' => (int) ($rows[$b]->sessions ?? 0) > 0 ? round(100 * (int) $rows[$b]->goals / (int) $rows[$b]->sessions, 1) : null,
            ];
            $out[] = [
                'node_id' => $id, 'title' => $node['title'], 'percent_a' => $node['config']['percent_a'],
                'goal' => $goal !== null ? ['node_id' => $goal, 'title' => $graph->node($goal)['title'] ?? $goal] : null,
                'a' => $variant('a'), 'b' => $variant('b'),
            ];
        }

        return $out;
    }

    // ── Test chat ────────────────────────────────────────────────────────────────────────────

    /**
     * Test chat: the draft runs on the real engine, with the data of a chosen contact if any.
     * Nothing is sent on WhatsApp and nothing is saved on the contact; API-call blocks do call out.
     * `action`: "timeout" makes the current step's "No reply" timer run out now.
     */
    public function test(Request $request, ChatbotFlow $flow, FlowEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'test_id' => ['nullable', 'string', 'max:40'],
            'contact_id' => ['nullable', 'uuid'],
            'text' => ['nullable', 'string', 'max:1000'],
            'payload' => ['nullable', 'string', 'max:300'],
            'type' => ['nullable', Rule::in(['text', 'image', 'document', 'audio', 'location', 'sticker'])],
            'action' => ['nullable', Rule::in(['timeout'])],
            'language' => ['nullable', 'string', 'regex:/^[a-z]{2}(-[A-Z]{2})?$/'],
        ]);
        $graph = FlowGraph::from($flow->draft);
        $settings = FlowSettings::normalize($flow->settings);
        $key = fn (string $id) => "chatbot-test:{$this->context->id()}:{$flow->id}:{$id}";

        $saved = isset($data['test_id']) ? Cache::get($key($data['test_id'])) : null;
        $testId = is_array($saved) ? (string) $data['test_id'] : Str::random(24);
        $contact = is_array($saved) ? $saved['contact'] : TestRuntime::contactFor(isset($data['contact_id']) ? Contact::query()->find($data['contact_id']) : null);
        $rt = new TestRuntime(is_array($saved) ? $saved['state'] : [], $contact, $flow->name);
        $type = $data['type'] ?? 'text';
        $content = match ($type) {
            'location' => ['latitude' => 25.2048, 'longitude' => 55.2708, 'name' => 'Test location', 'address' => 'Dubai'],
            'image', 'document', 'audio', 'sticker' => ['filename' => $type === 'document' ? 'test.pdf' : null],
            default => [],
        };
        $input = new BotInput($type === 'text' ? ($data['text'] ?? null) : null, $data['payload'] ?? null, false, $type, array_filter($content), 'test');

        if (! is_array($saved)) {
            if ($graph->start() === null) {
                throw ValidationException::withMessages(['flow' => 'Add a Start block first.']);
            }
            $first = isset($data['language']) && $data['language'] === 'ar' ? new BotInput('مرحبا') : null;
            $engine->begin($graph, $settings, $rt, $first);
            if (isset($data['language']) && in_array($data['language'], $settings['languages'], true)) {
                $rt->state['vars']['_lang'] = $data['language'];
            }
        } elseif (in_array($rt->state['status'], ChatbotSession::RUNNING, true)) {
            if ($rt->state['status'] === 'waiting') {
                // No one waits minutes in a test: continue after the Wait straight away.
                $engine->resume($graph, $settings, $rt);
            } elseif (($data['action'] ?? null) === 'timeout') {
                $engine->timeout($graph, $settings, $rt);
            } else {
                $engine->inbound($graph, $settings, $rt, $input);
            }
        } else {
            $rt->note('The flow has ended. Start again to test once more.');
        }

        Cache::put($key($testId), ['state' => $rt->state, 'contact' => $rt->contact], now()->addHours(2));
        $node = $graph->node($rt->state['current_node']);

        return response()->json(['data' => [
            'test_id' => $testId,
            'transcript' => $rt->transcript,
            'state' => [
                'status' => $rt->state['status'],
                'node_id' => $rt->state['current_node'],
                'awaiting_input' => $rt->state['awaiting_input'],
                'has_timeout' => $node !== null && $rt->state['awaiting_input'] && $graph->hasOutput($node['id'], 'timeout'),
                'language' => $rt->state['vars']['_lang'] ?? null,
                'vars' => (object) array_filter($rt->state['vars'], fn ($k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY),
                'contact' => $rt->contact,
            ],
        ]]);
    }

    /** Everything the builder's pickers need, in one call. */
    public function options(): JsonResponse
    {
        $numbers = PhoneNumber::query()->orderBy('created_at')->get();
        $wabas = $numbers->pluck('waba_account_id')->unique()->values();
        $tenant = $this->context->tenant();

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
            'subflows' => ChatbotFlow::query()->where('kind', 'subflow')->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'published_version'])
                ->map(fn (ChatbotFlow $f) => ['id' => $f->id, 'name' => $f->name, 'published' => $f->published_version !== null])->values(),
            'snippets' => ChatbotSnippet::query()->orderBy('name')->get(['id', 'name', 'body'])->values(),
            'variables' => (object) ChatbotWorkspace::variables($tenant),
            'variable_suggestions' => ChatbotWorkspace::SUGGESTED,
            'store_connected' => OrderLookup::connected(),
            'timezone' => $tenant->timezone ?? 'UTC',
        ]]);
    }

    // ── Workspace: business variables, agency approvals, snippets ────────────────────────────

    public function workspace(): JsonResponse
    {
        $tenant = $this->context->tenant();

        return response()->json(['data' => [
            'variables' => (object) ChatbotWorkspace::variables($tenant),
            'suggestions' => ChatbotWorkspace::SUGGESTED,
            'approvals' => ChatbotWorkspace::approvals($tenant),
        ]]);
    }

    public function saveWorkspace(Request $request): JsonResponse
    {
        $data = $request->validate([
            'variables' => ['sometimes', 'array', 'max:'.ChatbotWorkspace::MAX_VARIABLES],
            'approvals' => ['sometimes', 'boolean'],
        ]);
        $tenant = $this->context->tenant();
        $changes = [];
        if (isset($data['variables'])) {
            $changes['variables'] = ChatbotWorkspace::cleanVariables($data['variables']);
        }
        if (isset($data['approvals'])) {
            $this->context->authorize(Permission::ChatbotsPublish);
            $changes['approvals'] = $data['approvals'];
        }
        ChatbotWorkspace::save($tenant, $changes);
        $this->audit->record('chatbot.workspace_saved', null, meta: ['variables' => array_keys($changes['variables'] ?? []), 'approvals' => $changes['approvals'] ?? null]);

        return $this->workspace();
    }

    public function snippets(): JsonResponse
    {
        return response()->json(['data' => ChatbotSnippet::query()->orderBy('name')->get(['id', 'name', 'body', 'updated_at'])->values()]);
    }

    public function saveSnippet(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['nullable', 'uuid'], 'name' => ['required', 'string', 'max:80'], 'body' => ['required', 'string', 'max:4096']]);
        $row = isset($data['id']) ? ChatbotSnippet::query()->findOrFail($data['id']) : null;
        if (ChatbotSnippet::query()->where('name', trim($data['name']))->when($row !== null, fn (Builder $q) => $q->whereKeyNot($row->id))->exists()) {
            throw ValidationException::withMessages(['name' => 'A snippet with this name already exists.']);
        }
        if ($row === null && ChatbotSnippet::query()->count() >= 200) {
            throw ValidationException::withMessages(['name' => 'A workspace can keep up to 200 snippets.']);
        }
        $row ??= new ChatbotSnippet(['created_by_membership_id' => $this->context->membership()?->id]);
        $row->fill(['name' => trim($data['name']), 'body' => $data['body']])->save();

        return $this->snippets();
    }

    public function deleteSnippet(string $snippet): JsonResponse
    {
        ChatbotSnippet::query()->whereKey($snippet)->delete();

        return $this->snippets();
    }

    // ── Incoming-webhook trigger ─────────────────────────────────────────────────────────────

    public function webhookToken(Request $request, ChatbotFlow $flow): JsonResponse
    {
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $flow->forceFill(['webhook_token' => $on ? Str::lower(Str::random(40)) : null])->save();
        $this->audit->record($on ? 'chatbot.webhook_created' : 'chatbot.webhook_removed', $flow);

        return response()->json(['data' => $this->present($flow, full: true)]);
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
        $published = $flow->published_version !== null ? $this->chatbots->version($flow->id, $flow->published_version) : null;
        $start = ($published[0] ?? FlowGraph::from($flow->draft))->start()['config'] ?? null;
        $out = [
            'id' => $flow->id,
            'name' => $flow->name,
            'kind' => $flow->kind,
            'folder' => $flow->folder,
            'phone_number_id' => $flow->phone_number_id,
            'phone_number' => $number !== null ? [
                'id' => $number->id, 'display_phone_number' => $number->display_phone_number, 'verified_name' => $number->verified_name, 'status' => $number->status->value,
            ] : null,
            'is_active' => $flow->is_active,
            'status' => $flow->archived_at !== null ? 'archived' : ($flow->is_active ? 'live' : ($flow->published_version !== null ? 'paused' : 'draft')),
            'triggers' => $start !== null ? self::triggerSummary($start) : [],
            'role' => $start === null ? null : ($start['any_message'] ? 'default' : ($start['greeting'] ? 'greeting' : 'specific')),
            'published_version' => $flow->published_version,
            'published_at' => $flow->published_at?->toIso8601String(),
            'has_unpublished_changes' => $flow->hasUnpublishedChanges(),
            'draft_updated_at' => $flow->draft_updated_at?->toIso8601String(),
            'archived_at' => $flow->archived_at?->toIso8601String(),
            'activate_at' => $flow->activate_at?->toIso8601String(),
            'deactivate_at' => $flow->deactivate_at?->toIso8601String(),
            'review' => $flow->review_status !== null ? [
                'status' => $flow->review_status,
                'current' => $flow->review_status !== 'approved' || $flow->approvedForPublish(),
                'note' => $flow->review_note,
                'reviewed_at' => $flow->reviewed_at?->toIso8601String(),
                'reviewer' => $flow->reviewed_by_membership_id !== null ? ($this->memberNames([$flow->reviewed_by_membership_id])[$flow->reviewed_by_membership_id] ?? null) : null,
            ] : null,
            'created_at' => $flow->created_at?->toIso8601String(),
            'updated_at' => $flow->updated_at?->toIso8601String(),
        ];
        if ($full) {
            $issues = $this->issues($flow);
            $out += [
                'draft' => FlowGraph::normalize($flow->draft),
                'settings' => FlowSettings::normalize($flow->settings),
                'issues' => $issues,
                'editors' => $flow->editors,
                'webhook_url' => $flow->webhook_token !== null ? url('/api/chatbots/hooks/'.$flow->webhook_token) : null,
                'approvals_required' => ChatbotWorkspace::approvals($this->context->tenant()),
                'open_comments' => ChatbotNodeComment::query()->where('flow_id', $flow->id)->whereNull('resolved_at')->count(),
                'versions' => ChatbotFlowVersion::query()->where('flow_id', $flow->id)->orderByDesc('version')->limit(30)->get()
                    ->map(fn (ChatbotFlowVersion $v) => ['version' => $v->version, 'created_at' => $v->created_at?->toIso8601String(), 'published_by_membership_id' => $v->published_by_membership_id])->values(),
                'can' => $this->abilities($flow),
            ];
        }

        return $out;
    }

    /**
     * What starts a flow, in words, for the list and the builder's top bar.
     *
     * @param  array<string, mixed>  $c
     * @return list<string>
     */
    private static function triggerSummary(array $c): array
    {
        $out = [];
        if ($c['any_message']) {
            $out[] = 'Any message';
        }
        if ($c['greeting']) {
            $out[] = 'First message (greeting)';
        }
        if ($c['keywords'] !== []) {
            $out[] = 'Keywords: '.implode(', ', array_slice($c['keywords'], 0, 4)).(count($c['keywords']) > 4 ? '…' : '');
        }
        if ($c['regex'] !== null) {
            $out[] = 'Pattern';
        }
        if ($c['qr_code'] !== null) {
            $out[] = 'QR code “'.$c['qr_code'].'”';
        }
        if ($c['campaign_buttons'] !== []) {
            $out[] = 'Campaign button: '.implode(', ', array_slice($c['campaign_buttons'], 0, 3));
        }
        if ($c['ad_referral'] && ! $c['any_message']) {
            $out[] = 'Click-to-WhatsApp ad';
        }
        if ($c['tags_added'] !== []) {
            $out[] = 'Tag added: '.implode(', ', $c['tags_added']);
        }
        if ($c['fields_changed'] !== []) {
            $out[] = 'Field changed: '.implode(', ', $c['fields_changed']);
        }
        if ($c['webhook']) {
            $out[] = 'Incoming webhook';
        }

        return $out;
    }

    /** Dependency checks on what is live: a template rejected or a field deleted since publishing. */
    public const DEPENDENCY_CODES = ['deleted_field', 'missing_template', 'template_status', 'missing_secret', 'missing_member', 'deleted_segment', 'missing_subflow', 'missing_file', 'no_store', 'reopen_template'];

    /** @return list<string> */
    private function attention(ChatbotFlow $flow): array
    {
        if (! $flow->is_active || $flow->published_version === null) {
            return [];
        }
        $version = $this->chatbots->version($flow->id, $flow->published_version);
        if ($version === null) {
            return [];
        }
        $problems = array_filter($this->issues($flow, $version[0], $version[1], gate: false), fn ($i) => $i['level'] === 'error' && in_array($i['code'], self::DEPENDENCY_CODES, true));

        return array_slice(array_values(array_map(fn ($i) => $i['message'], $problems)), 0, 3);
    }

    /** @return array<string, bool> */
    private function abilities(?ChatbotFlow $flow = null): array
    {
        $manage = $this->context->can(Permission::ChatbotsManage);
        $publish = $this->context->can(Permission::ChatbotsPublish);
        $me = $this->context->membership()?->id;

        return [
            'manage' => $manage,
            'publish' => $publish,
            'edit' => $manage && ($flow === null || $flow->editors === null || $publish || in_array($me, (array) $flow->editors, true)),
        ];
    }

    private function assertCanEdit(ChatbotFlow $flow): void
    {
        if (! $this->abilities($flow)['edit']) {
            throw new AuthorizationException('Only the editors chosen for this flow can change it.');
        }
    }

    /**
     * The publish checks, with this workspace's dependencies, plus the agency approval.
     *
     * @param  ?array<string, mixed>  $settings
     * @return list<array{level: string, code: string, node_id: ?string, output_id: ?string, message: string, fix: string}>
     */
    private function issues(ChatbotFlow $flow, ?FlowGraph $graph = null, ?array $settings = null, bool $gate = true): array
    {
        $graph ??= FlowGraph::from($flow->draft);
        $settings ??= FlowSettings::normalize($flow->settings);
        $types = array_column($graph->nodes(), 'type');
        $deps = ['kind' => $flow->kind, 'settings' => $settings];
        $deps['fields'] = array_fill_keys(ContactField::query()->pluck('key')->all(), true);

        if (in_array('template', $types, true) || $settings['reopen_template'] !== null) {
            $wabas = $flow->phone_number_id !== null
                ? PhoneNumber::query()->whereKey($flow->phone_number_id)->pluck('waba_account_id')
                : PhoneNumber::query()->pluck('waba_account_id');
            $known = MessageTemplate::query()->whereIn('waba_account_id', $wabas)->get(['name', 'language', 'status'])
                ->mapWithKeys(fn (MessageTemplate $t) => [$t->name.'|'.$t->language => (string) $t->status])->all();
            // Templates never synced yet: WhatsApp checks them when sending (as SendMessage does).
            if ($known !== []) {
                $deps['templates'] = $known;
            }
        }
        if (in_array('http', $types, true)) {
            $deps['secrets'] = array_fill_keys(ChatbotSecret::query()->pluck('name')->all(), true);
        }
        if (array_intersect($types, ['tag', 'handoff', 'end']) !== []) {
            $deps['members'] = array_fill_keys(TenantMembership::query()->active()->pluck('id')->all(), true);
        }
        if (in_array('condition', $types, true)) {
            $deps['segments'] = [];
            foreach (Segment::query()->get(['id', 'name']) as $s) {
                $deps['segments'][(string) $s->id] = true;
                $deps['segments'][mb_strtolower((string) $s->name)] = true;
            }
        }
        if (in_array('subflow', $types, true)) {
            $deps['subflows'] = ChatbotFlow::query()->where('kind', 'subflow')->whereNull('archived_at')->get(['id', 'published_version'])
                ->mapWithKeys(fn (ChatbotFlow $f) => [$f->id => $f->published_version !== null])->all();
        }
        if (in_array('media', $types, true)) {
            $ids = array_values(array_filter(array_map(fn ($n) => $n['type'] === 'media' ? $n['config']['media_id'] : null, $graph->nodes())));
            $deps['media'] = array_fill_keys(Media::query()->whereIn('id', $ids)->pluck('id')->all(), true);
        }
        if (in_array('order', $types, true)) {
            $deps['stores'] = OrderLookup::connected();
        }

        $issues = FlowChecks::run($graph, $deps);
        if ($settings['reopen_template'] !== null && isset($deps['templates'])) {
            $t = $settings['reopen_template'];
            if (($deps['templates'][$t['name'].'|'.$t['language']] ?? null) !== 'APPROVED') {
                $issues[] = ['level' => 'error', 'code' => 'reopen_template', 'node_id' => null, 'output_id' => null,
                    'message' => "The re-opening template “{$t['name']}” is not approved on this number.", 'fix' => 'Choose an approved template in Flow settings → 24-hour window.'];
            }
        }
        if ($gate && ChatbotWorkspace::approvals($this->context->tenant()) && ! $flow->approvedForPublish()) {
            $issues[] = ['level' => 'error', 'code' => 'approval', 'node_id' => null, 'output_id' => null,
                'message' => match ($flow->review_status) {
                    'requested' => 'Waiting for a reviewer to approve this flow.',
                    'changes_requested' => 'The reviewer asked for changes'.($flow->review_note !== null ? ': “'.$flow->review_note.'”' : '.'),
                    'approved' => 'The flow changed after it was approved.',
                    default => 'This workspace needs a review before publishing.',
                },
                'fix' => $flow->review_status === 'requested' ? 'A publisher approves it from the Review button.' : 'Click Review → Request review; a publisher approves it.'];
        }

        return $issues;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function memberNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TenantMembership::query()->whereIn('id', $ids)->with('user:id,name')->get()
            ->mapWithKeys(fn (TenantMembership $m) => [$m->id => $m->user->name ?? 'Teammate'])->all();
    }

    /** @return array{nodes: list<array<string, mixed>>} */
    private static function subflowDraft(): array
    {
        return ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'title' => 'Subflow starts', 'x' => 60, 'y' => 60, 'config' => ['any_message' => false, 'ad_referral' => false], 'outputs' => [['id' => 'next', 'to' => 'message']]],
            ['id' => 'message', 'type' => 'message', 'title' => 'Message', 'x' => 440, 'y' => 60, 'config' => ['text' => 'This part is shared by several flows.'], 'outputs' => [['id' => 'next', 'to' => 'end']]],
            ['id' => 'end', 'type' => 'end', 'title' => 'Back to the flow', 'x' => 820, 'y' => 60, 'config' => ['action' => 'none'], 'outputs' => []],
        ]];
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
