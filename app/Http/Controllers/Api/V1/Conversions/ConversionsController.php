<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Conversions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Chatbots\Engine\FlowGraph;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Conversions\Models\AdClick;
use App\Domain\Conversions\Models\ConversionEvent;
use App\Domain\Conversions\Models\ConversionRule;
use App\Domain\Conversions\Models\MetaDataset;
use App\Domain\Conversions\Services\ConversionReporting;
use App\Domain\Conversions\Services\ConversionRules;
use App\Domain\Conversions\Services\ConversionSender;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Integrations › "Meta conversion reporting" (Conversions to Meta, batch 3b). Only for workspaces with
 * the conversions flag: every other workspace gets 404 and sees no card.
 */
final class ConversionsController extends Controller
{
    public const HELP_URL = 'https://business.facebook.com/events_manager2/';

    /** What a status means, for the events log. */
    private const STATUS = ['queued' => 'Waiting', 'sent' => 'Sent', 'failed' => 'Failed', 'expired' => 'Expired', 'skipped' => 'Skipped'];

    public function __construct(private readonly TenantContext $context) {}

    private function ensureAllowed(): void
    {
        abort_unless(ConversionsAccess::allowed($this->context->tenant()), 404);
    }

    /** The card: each WhatsApp account (permission, reporting, dataset), the rules, and what can be picked for them. */
    public function show(): JsonResponse
    {
        $this->ensureAllowed();
        $datasets = MetaDataset::query()->get()->keyBy('waba_id');
        $accounts = WabaAccount::query()->where('status', 'connected')->orderBy('connected_at')->get()->map(fn (WabaAccount $w) => [
            'id' => $w->id,
            'waba_id' => $w->waba_id,
            'name' => $w->name ?? $w->business_name ?? $w->waba_id,
            // Enabled / Not enabled: from the account's token as last checked with debug_token.
            'permission' => match ($w->events_permission) {
                true => 'enabled', false => 'not_enabled', default => 'unknown'
            },
            'permission_checked_at' => $w->events_permission_checked_at?->toIso8601String(),
            'reporting' => (bool) ($datasets->get($w->waba_id)->enabled ?? false),
            'dataset_id' => $datasets->get($w->waba_id)?->dataset_id,
        ])->values();

        $rules = ConversionRule::query()->get();
        $flows = ChatbotFlow::query()->whereNotNull('published_version')->whereNull('archived_at')->where('kind', 'bot')->orderBy('name')->get();

        return response()->json(['data' => [
            'accounts' => $accounts,
            'rules' => [
                'lead_tags' => $rules->where('trigger_type', ConversionRule::TAG)->where('enabled', true)->pluck('trigger_ref')->values(),
                'lead_steps' => $rules->where('trigger_type', ConversionRule::FLOW_STEP)->where('enabled', true)->map(function (ConversionRule $r) use ($flows) {
                    [$flowId, $stepId] = explode(':', (string) $r->trigger_ref, 2) + [1 => ''];
                    $flow = $flows->firstWhere('id', $flowId);
                    $node = $flow !== null ? FlowGraph::from($flow->publishedGraph()?->graph)->node($stepId) : null;

                    return ['flow_id' => $flowId, 'step_id' => $stepId, 'label' => ($flow->name ?? 'Deleted flow').' › '.($node['title'] ?? $stepId)];
                })->values(),
                'purchases' => $rules->where('trigger_type', ConversionRule::ORDER)->where('enabled', true)->isNotEmpty(),
            ],
            // Steps that can count as a lead: those of published flows (what real runs follow).
            'flows' => $flows->map(fn (ChatbotFlow $f) => ['id' => $f->id, 'name' => $f->name, 'steps' => collect(FlowGraph::from($f->publishedGraph()?->graph)->nodes())
                ->reject(fn (array $n) => $n['type'] === 'start')
                ->map(fn (array $n) => ['id' => $n['id'], 'title' => (string) ($n['title'] ?? $n['id']), 'type' => $n['type']])->values()])->values(),
            'help_url' => self::HELP_URL,
        ]]);
    }

    /** On/off for one account. On creates (or finds) the account's dataset at Meta. */
    public function reporting(Request $request, WabaAccount $account, ConversionReporting $reporting): JsonResponse
    {
        $this->ensureAllowed();
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        if (! $on) {
            $reporting->disable($account);

            return response()->json(['data' => ['result' => 'disabled']]);
        }
        $result = $reporting->enable($this->context->tenant(), $account);
        if ($result === ConversionReporting::NOT_ENABLED) {
            throw ValidationException::withMessages(['enabled' => 'Not enabled: this WhatsApp account hasn’t given engageX permission to send conversion events. Reconnect it from Channels (Reconnect to enable), then try again.']);
        }

        return response()->json(['data' => ['result' => $result, 'dataset_id' => $reporting->datasetFor($account->waba_id)?->dataset_id]]);
    }

    /** The rules: which tags and flow steps count as a lead, and whether linked orders count as purchases. */
    public function saveRules(Request $request, ConversionRules $rules, AuditLogger $audit): JsonResponse
    {
        $this->ensureAllowed();
        $data = $request->validate([
            'lead_tags' => ['present', 'array', 'max:20'],
            'lead_tags.*' => ['string', 'max:60'],
            'lead_steps' => ['present', 'array', 'max:20'],
            'lead_steps.*.flow_id' => ['required', 'uuid'],
            'lead_steps.*.step_id' => ['required', 'string', 'max:64'],
            'purchases' => ['required', 'boolean'],
        ]);

        $tags = array_values(array_unique(array_map(fn ($t) => mb_strtolower(trim((string) $t)), $data['lead_tags'])));
        $steps = array_values(array_unique(array_map(fn ($s) => $s['flow_id'].':'.$s['step_id'], $data['lead_steps'])));
        foreach ($steps as $ref) {
            [$flowId, $stepId] = explode(':', $ref, 2);
            $flow = ChatbotFlow::query()->find($flowId);
            if ($flow === null || FlowGraph::from($flow->publishedGraph()?->graph)->node($stepId) === null) {
                throw ValidationException::withMessages(['lead_steps' => 'Pick a step of a published flow.']);
            }
        }

        // Rules not picked any more are switched off (kept, so their past events stay linked).
        foreach (ConversionRule::query()->whereIn('trigger_type', [ConversionRule::TAG, ConversionRule::FLOW_STEP])->get() as $rule) {
            $keep = $rule->trigger_type === ConversionRule::TAG ? in_array($rule->trigger_ref, $tags, true) : in_array($rule->trigger_ref, $steps, true);
            if (! $keep && $rule->enabled) {
                $rule->trigger_type === ConversionRule::TAG ? $rules->leadOnTag((string) $rule->trigger_ref, false)
                    : $rules->leadOnFlowStep(...array_merge(explode(':', (string) $rule->trigger_ref, 2), [false]));
            }
        }
        foreach ($tags as $tag) {
            $rules->leadOnTag($tag);
        }
        foreach ($steps as $ref) {
            [$flowId, $stepId] = explode(':', $ref, 2);
            $rules->leadOnFlowStep($flowId, $stepId);
        }
        $rules->purchasesFromOrders((bool) $data['purchases']);
        $audit->record('conversions.rules_saved', meta: ['lead_tags' => $tags, 'lead_steps' => $steps, 'purchases' => (bool) $data['purchases']]);

        return $this->show();
    }

    /** The events log: time, contact, event, value, ad, status and the reason in plain words. */
    public function events(Request $request): JsonResponse
    {
        $this->ensureAllowed();
        $f = $request->validate([
            'status' => ['nullable', 'in:queued,sent,failed,expired,skipped'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $zone = $this->context->tenant()->timezone ?: 'UTC';
        $page = ConversionEvent::query()
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('event_time', '>=', Carbon::parse($d, $zone)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('event_time', '<=', Carbon::parse($d, $zone)->endOfDay()))
            ->orderByDesc('event_time')->paginate(25);
        $contacts = Contact::query()->whereIn('id', $page->pluck('contact_id')->filter()->unique()->all())->get()->keyBy('id');
        $clicks = AdClick::query()->whereIn('id', $page->pluck('ad_click_id')->filter()->unique()->all())->get()->keyBy('id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (ConversionEvent $e) => [
                'id' => $e->id,
                'time' => $e->event_time->toIso8601String(),
                'contact' => $e->contact_id !== null ? ['id' => $e->contact_id, 'name' => $contacts->get($e->contact_id)?->displayName() ?? 'Deleted contact'] : null,
                'conversation_id' => $e->conversation_id,
                'event' => $e->event_name,
                'value' => $e->value !== null ? (float) $e->value : null,
                'currency' => $e->currency,
                'ad' => ($click = $clicks->get((string) $e->ad_click_id)) !== null ? ['source_id' => $click->source_id, 'headline' => $click->headline] : null,
                'status' => $e->status,
                'status_label' => self::STATUS[$e->status] ?? $e->status,
                'reason' => $e->error_reason,
                'sent_at' => $e->sent_at?->toIso8601String(),
                'is_test' => $e->is_test,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Growth Hub › Meta conversions: leads, purchases and revenue per ad (the ad's source_id and
     * headline), for a period in the workspace's time zone. Counts every real conversion recorded from
     * an ad-started chat (test events never count), and how many of them reached Meta. No cost per
     * result or return on ad spend: there is no ad spend data.
     */
    public function results(Request $request): JsonResponse
    {
        $this->ensureAllowed();
        $f = $request->validate([
            'days' => ['nullable', 'integer', 'in:7,30,90,365'],
            'since' => ['nullable', 'date_format:Y-m-d', 'required_with:until'],
            'until' => ['nullable', 'date_format:Y-m-d', 'required_with:since', 'after_or_equal:since'],
        ]);
        $zone = $this->context->tenant()->timezone ?: 'UTC';
        [$from, $to] = isset($f['since'], $f['until'])
            ? [Carbon::parse($f['since'], $zone)->startOfDay(), Carbon::parse($f['until'], $zone)->endOfDay()]
            : [now($zone)->subDays((int) ($f['days'] ?? 30))->startOfDay(), now($zone)->endOfDay()];

        $rows = DB::table('conversion_events as e')
            ->join('ad_clicks as c', 'c.id', '=', 'e.ad_click_id')
            ->where('e.is_test', false)
            ->whereBetween('e.event_time', [$from, $to])
            ->groupBy('c.source_id', 'e.currency')
            ->selectRaw("c.source_id,
                (array_agg(c.headline ORDER BY c.clicked_at DESC) FILTER (WHERE c.headline IS NOT NULL))[1] AS headline,
                e.currency,
                count(*) FILTER (WHERE e.event_name = ?) AS leads,
                count(*) FILTER (WHERE e.event_name = ?) AS purchases,
                coalesce(sum(e.value) FILTER (WHERE e.event_name = ?), 0) AS revenue,
                count(*) FILTER (WHERE e.status = 'sent') AS sent,
                max(e.event_time) AS last_at", [ConversionRule::LEAD, ConversionRule::PURCHASE, ConversionRule::PURCHASE])
            ->get();

        // One row per ad; revenue per currency (a workspace normally has one).
        $ads = $rows->groupBy(fn ($r) => (string) ($r->source_id ?? ''))->map(function ($group, string $sourceId) {
            $revenue = $group->filter(fn ($r) => $r->currency !== null && (float) $r->revenue > 0)
                ->map(fn ($r) => ['currency' => (string) $r->currency, 'value' => round((float) $r->revenue, 2)])->values();

            return [
                'source_id' => $sourceId === '' ? null : $sourceId,
                'headline' => $group->pluck('headline')->filter()->first(),
                'leads' => (int) $group->sum('leads'),
                'purchases' => (int) $group->sum('purchases'),
                'revenue' => $revenue,
                'sent_to_meta' => (int) $group->sum('sent'),
                'last_at' => Carbon::parse((string) $group->max('last_at'))->toIso8601String(),
            ];
        })->sortByDesc(fn ($a) => [$a['purchases'], $a['leads']])->values();

        $revenue = $rows->filter(fn ($r) => $r->currency !== null)->groupBy('currency')
            ->map(fn ($g, $cur) => ['currency' => (string) $cur, 'value' => round((float) $g->sum('revenue'), 2)])
            ->filter(fn ($r) => $r['value'] > 0)->values();

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => ['leads' => $ads->sum('leads'), 'purchases' => $ads->sum('purchases'), 'revenue' => $revenue, 'sent_to_meta' => $ads->sum('sent_to_meta')],
            'ads' => $ads,
        ]]);
    }

    /** Recent chats that started from a Meta ad (with a click ID), to send a test event for. */
    public function testChats(): JsonResponse
    {
        $this->ensureAllowed();
        $clicks = AdClick::query()->whereNotNull('ctwa_clid')->orderByDesc('clicked_at')->limit(50)->get()->unique('conversation_id')->take(20);
        $contacts = Contact::query()->whereIn('id', $clicks->pluck('contact_id')->all())->get()->keyBy('id');

        return response()->json(['data' => $clicks->map(fn (AdClick $c) => [
            'conversation_id' => $c->conversation_id,
            'contact' => $contacts->get($c->contact_id)?->displayName() ?? 'Deleted contact',
            'ad' => ['source_id' => $c->source_id, 'headline' => $c->headline],
            'clicked_at' => $c->clicked_at->toIso8601String(),
            'waba_id' => $c->waba_id,
        ])->values()]);
    }

    /** "Send test event": one LeadSubmitted for an ad-started chat, sent now; the result comes back straight away. */
    public function testEvent(Request $request, ConversionReporting $reporting, ConversionSender $sender, AuditLogger $audit): JsonResponse
    {
        $this->ensureAllowed();
        $conversationId = (string) $request->validate(['conversation_id' => ['required', 'uuid']])['conversation_id'];
        $conversation = Conversation::query()->findOrFail($conversationId);
        $click = AdClick::query()->where('conversation_id', $conversation->id)->whereNotNull('ctwa_clid')->orderByDesc('clicked_at')->first();
        if ($click === null) {
            throw ValidationException::withMessages(['conversation_id' => 'This chat did not start from a Meta ad: there is no click ID to send.']);
        }
        if ($reporting->datasetFor($click->waba_id) === null) {
            throw ValidationException::withMessages(['conversation_id' => 'Switch conversion reporting on for this WhatsApp account first.']);
        }

        $id = (string) Str::uuid7();
        DB::table('conversion_events')->insert([
            'id' => $id, 'tenant_id' => $conversation->tenant_id, 'conversation_id' => $conversation->id, 'contact_id' => $conversation->contact_id,
            'ad_click_id' => $click->id, 'waba_id' => $click->waba_id, 'event_name' => ConversionRule::LEAD, 'event_time' => now(),
            'status' => ConversionEvent::QUEUED, 'next_attempt_at' => now(), 'is_test' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sender->send($click->waba_id, $id);
        $event = ConversionEvent::query()->findOrFail($id);
        $audit->record('conversions.test_event', meta: ['conversation_id' => $conversation->id, 'status' => $event->status]);

        return response()->json(['data' => [
            'id' => $event->id,
            'status' => $event->status,
            'status_label' => self::STATUS[$event->status] ?? $event->status,
            'reason' => $event->error_reason,
            'meta_response' => array_intersect_key((array) $event->meta_response, array_flip(['events_received', 'fbtrace_id', 'message', 'code'])),
        ]]);
    }
}
