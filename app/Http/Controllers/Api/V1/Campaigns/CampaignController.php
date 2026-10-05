<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Campaigns;

use App\Application\Campaigns\ManageCampaigns;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Models\CampaignRecipient;
use App\Domain\Crm\Models\Segment;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Campaigns (broadcasts): an approved template sent to a segment, with delivery numbers. */
final class CampaignController extends Controller
{
    public function __construct(private readonly ManageCampaigns $campaigns) {}

    public function index(Request $request): JsonResponse
    {
        $list = Campaign::query()->with('phoneNumber')->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $list->map(fn (Campaign $c) => $this->present($c))]);
    }

    public function show(Campaign $campaign): JsonResponse
    {
        return response()->json(['data' => $this->present($campaign->load('phoneNumber'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $campaign = $this->campaigns->save(null, $this->input($request));

        return response()->json(['data' => $this->present($campaign->load('phoneNumber'))], 201);
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        return response()->json(['data' => $this->present($this->campaigns->save($campaign, $this->input($request))->load('phoneNumber'))]);
    }

    public function destroy(Campaign $campaign): JsonResponse
    {
        $this->campaigns->delete($campaign);

        return response()->json(null, 204);
    }

    /** Audience size for a template category before saving: "182 match · 176 eligible". */
    public function audience(Request $request, EntitlementService $entitlements, TenantContext $context): JsonResponse
    {
        $data = $request->validate(['segment_id' => ['nullable', 'uuid'], 'template_id' => ['nullable', 'uuid']]);
        $segment = isset($data['segment_id']) ? Segment::query()->findOrFail($data['segment_id']) : null;
        $category = isset($data['template_id']) ? MessageTemplate::query()->find($data['template_id'])?->category : null;

        $counts = $this->campaigns->counts($segment !== null ? ['match' => $segment->match, 'rules' => $segment->rules] : null, $category);
        $reach = $entitlements->for($context->tenant())->get(FeatureKey::CampaignReachMonthly);

        return response()->json(['data' => $counts + [
            'category' => $category,
            'reach_limit' => $reach->limit,
            'reach_used' => $entitlements->usage($context->tenant(), FeatureKey::CampaignReachMonthly) ?? 0,
        ]]);
    }

    public function launch(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate(['scheduled_at' => ['nullable', 'date', 'after:now', 'before:+6 months']]);

        $campaign = $this->campaigns->launch($campaign, isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null);

        return response()->json(['data' => $this->present($campaign->load('phoneNumber'))]);
    }

    public function cancel(Campaign $campaign): JsonResponse
    {
        return response()->json(['data' => $this->present($this->campaigns->cancel($campaign)->load('phoneNumber'))]);
    }

    /** Who got it, who was skipped and why. */
    public function recipients(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'queued', 'skipped', 'failed'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = CampaignRecipient::query()->with(['contact', 'message'])->where('campaign_id', $campaign->id)
            ->when($data['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->orderBy('id')->cursorPaginate((int) ($data['per_page'] ?? 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (CampaignRecipient $r) => [
                'id' => $r->id,
                'contact' => $r->contact !== null ? ['id' => $r->contact->id, 'display_name' => $r->contact->displayName(), 'phone' => $r->contact->wa_id !== null ? '+'.$r->contact->wa_id : null] : null,
                // One word for the whole journey: before WhatsApp (recipient) or after (message).
                'status' => $r->message !== null ? $r->message->status->value : $r->status,
                'reason' => $r->message->error_title ?? $r->reason,
            ]),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone_number_id' => ['required', 'uuid'],
            'template_id' => ['required', 'uuid'],
            'segment_id' => ['nullable', 'uuid'],
            'media_id' => ['nullable', 'uuid'],
            'variables' => ['nullable', 'array'],
            'variables.header' => ['nullable', 'array', 'max:1'],
            'variables.header.*' => ['nullable', 'string', 'max:60'],
            'variables.body' => ['nullable', 'array', 'max:50'],
            'variables.body.*' => ['nullable', 'string', 'max:1024'],
            'variables.buttons' => ['nullable', 'array', 'max:10'],
            'variables.buttons.*' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Campaign $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status->value,
            'phone_number' => $c->phoneNumber !== null ? [
                'id' => $c->phoneNumber->id,
                'display' => $c->phoneNumber->verified_name ?? $c->phoneNumber->display_phone_number,
            ] : null,
            'template' => ['id' => $c->message_template_id, 'name' => $c->template_name, 'language' => $c->template_language, 'category' => $c->template_category],
            'variables' => $c->variables ?? ['header' => [], 'body' => [], 'buttons' => (object) []],
            'media_id' => $c->media_id,
            'segment_id' => $c->segment_id,
            'audience_name' => $c->audience['name'] ?? null,
            'scheduled_at' => $c->scheduled_at?->toIso8601String(),
            'started_at' => $c->started_at?->toIso8601String(),
            'completed_at' => $c->completed_at?->toIso8601String(),
            'failure_reason' => $c->failure_reason,
            'stats' => $this->campaigns->stats($c),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
