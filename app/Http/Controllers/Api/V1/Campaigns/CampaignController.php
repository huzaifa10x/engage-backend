<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Campaigns;

use App\Application\Campaigns\ManageCampaigns;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Models\CampaignRecipient;
use App\Domain\Campaigns\Services\Personalizer;
use App\Domain\Compliance\ComplianceSettings;
use App\Domain\Crm\Models\Segment;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        return response()->json(['data' => $this->present($campaign->load('phoneNumber')) + ['failure_reasons' => $this->campaigns->failureReasons($campaign)]]);
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

    /**
     * Audience size before saving — "182 match · 176 eligible" — with every limit that could
     * hold the send back: the plan's monthly reach, Meta's per-number 24-hour limit, the number's
     * quality rating, the frequency cap and quiet hours.
     */
    public function audience(Request $request, EntitlementService $entitlements, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'segment_id' => ['nullable', 'uuid'],
            'audience_tag' => ['nullable', 'string', 'max:40'],
            'template_id' => ['nullable', 'uuid'],
            'phone_number_id' => ['nullable', 'uuid'],
        ]);
        $segment = isset($data['segment_id']) ? Segment::query()->findOrFail($data['segment_id']) : null;
        $category = isset($data['template_id']) ? MessageTemplate::query()->find($data['template_id'])?->category : null;
        $number = isset($data['phone_number_id']) ? PhoneNumber::query()->find($data['phone_number_id']) : null;
        $tenant = $context->tenant();
        $settings = ComplianceSettings::for($tenant);
        $marketing = strtoupper((string) $category) === 'MARKETING';

        $counts = $this->campaigns->counts(['match' => $segment->match ?? 'all', 'rules' => $segment->rules ?? [], 'tag' => $data['audience_tag'] ?? null], $category);
        $reach = $entitlements->for($tenant)->get(FeatureKey::CampaignReachMonthly);

        return response()->json(['data' => $counts + [
            'category' => $category,
            'reach_limit' => $reach->limit,
            'reach_used' => $entitlements->usage($tenant, FeatureKey::CampaignReachMonthly) ?? 0,
            // Meta: business-initiated conversations this number may open per rolling 24 hours.
            'messaging_limit' => ManageCampaigns::messagingLimit($number?->messaging_limit_tier),
            'messaging_limit_tier' => $number?->messaging_limit_tier,
            'quality_rating' => $number?->quality_rating,
            'max_mps' => $number?->max_mps,
            // Sending speed for this workspace's plan; not selectable by the sender.
            'send_rate_per_hour' => $this->campaigns->sendRatePerHour(),
            'frequency_cap' => $marketing ? $settings->marketingFrequencyCap : 0,
            'quiet_until' => $marketing ? $settings->quietUntil(now(), $tenant->timezone ?: 'UTC')?->toIso8601String() : null,
            'quiet_hours' => $settings->quietHoursEnabled ? ['start' => $settings->quietHoursStart, 'end' => $settings->quietHoursEnd, 'timezone' => $tenant->timezone ?: 'UTC'] : null,
        ]]);
    }

    /** The message as real recipients will see it: the first eligible contacts, variables filled in. */
    public function preview(Request $request, Personalizer $personalizer): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'uuid'],
            'segment_id' => ['nullable', 'uuid'],
            'audience_tag' => ['nullable', 'string', 'max:40'],
            'variables' => ['nullable', 'array'],
            'variables.header' => ['nullable', 'array', 'max:1'],
            'variables.header.*' => ['nullable', 'string', 'max:60'],
            'variables.body' => ['nullable', 'array', 'max:50'],
            'variables.body.*' => ['nullable', 'string', 'max:1024'],
        ]);
        $template = MessageTemplate::query()->findOrFail($data['template_id']);
        $segment = isset($data['segment_id']) ? Segment::query()->findOrFail($data['segment_id']) : null;
        $definition = $template->variables();
        $fill = function (string $text, array $names, array $values): string {
            foreach ($names as $i => $name) {
                $text = (string) preg_replace('/\{\{\s*'.preg_quote((string) $name, '/').'\s*\}\}/', addcslashes((string) ($values[$i] ?? ''), '\\$'), $text);
            }

            return $text;
        };

        $contacts = $this->campaigns->eligibleQuery(['match' => $segment->match ?? 'all', 'rules' => $segment->rules ?? [], 'tag' => $data['audience_tag'] ?? null], $template->category)
            ->orderBy('id')->limit(3)->get();

        return response()->json(['data' => $contacts->map(function (Contact $contact) use ($template, $definition, $data, $personalizer, $fill) {
            $header = array_map(fn ($v) => $personalizer->resolve((string) $v, $contact), array_values((array) ($data['variables']['header'] ?? [])));
            $body = array_map(fn ($v) => $personalizer->resolve((string) $v, $contact), array_values((array) ($data['variables']['body'] ?? [])));

            return [
                'contact' => ['id' => $contact->id, 'display_name' => $contact->displayName(), 'phone' => $contact->wa_id !== null ? '+'.$contact->wa_id : null],
                'header' => $template->headerFormat() === 'TEXT' ? $fill((string) ($template->component('HEADER')['text'] ?? ''), $definition['header'], $header) : null,
                'body' => $fill((string) ($template->component('BODY')['text'] ?? ''), $definition['body'], $body),
                'footer' => $template->component('FOOTER')['text'] ?? null,
                // An empty personalised value means this contact would be skipped, never sent "{{1}}".
                'skipped' => in_array('', array_merge($header, $body), true),
            ];
        })]);
    }

    public function pause(Campaign $campaign): JsonResponse
    {
        return response()->json(['data' => $this->present($this->campaigns->pause($campaign)->load('phoneNumber'))]);
    }

    public function resume(Campaign $campaign): JsonResponse
    {
        return response()->json(['data' => $this->present($this->campaigns->resume($campaign)->load('phoneNumber'))]);
    }

    public function duplicate(Campaign $campaign): JsonResponse
    {
        return response()->json(['data' => $this->present($this->campaigns->duplicate($campaign)->load('phoneNumber'))], 201);
    }

    /** Per-recipient report as CSV: who got it, who did not, and why. */
    public function export(Campaign $campaign, TenantContext $context): StreamedResponse
    {
        $tenant = $context->tenant();
        $membership = $context->membership();
        $query = CampaignRecipient::query()->with(['contact' => fn ($q) => $q->withTrashed(), 'message'])->where('campaign_id', $campaign->id)->orderBy('id');

        return response()->streamDownload(fn () => $context->run($tenant, function () use ($query): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['phone', 'name', 'status', 'reason', 'error_code', 'sent_at'], ',', '"', '');
            $query->chunk(1000, function ($rows) use ($out): void {
                /** @var CampaignRecipient $r */
                foreach ($rows as $r) {
                    $name = (string) ($r->contact->name ?? $r->contact->profile_name ?? '');
                    fputcsv($out, [
                        $r->contact?->wa_id !== null ? '+'.$r->contact->wa_id : '',
                        preg_match('/^[=+\-@]/', $name) === 1 ? "'".$name : $name,
                        $r->message !== null ? ($r->message->status->value === 'accepted' ? 'sent' : $r->message->status->value) : $r->status,
                        (string) ($r->message->error_title ?? $r->reason ?? ''),
                        (string) ($r->message->error_code ?? ''),
                        $r->message?->created_at?->utc()->format('Y-m-d H:i:s') ?? '',
                    ], ',', '"', '');
                }
            });
            fclose($out);
        }, $membership), 'campaign-'.preg_replace('/[^a-z0-9]+/i', '-', $campaign->name).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
            'audience_tag' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'objective' => ['nullable', Rule::in(['promo', 'announcement', 're_engagement', 'reminder', 'update', 'other'])],
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
            'audience_tag' => $c->audience_tag,
            'audience_name' => $c->audience['name'] ?? null,
            'notes' => $c->notes,
            'objective' => $c->objective,
            'batch_per_hour' => $c->batch_per_hour,
            'next_batch_at' => $c->next_batch_at?->toIso8601String(),
            'paused_at' => $c->paused_at?->toIso8601String(),
            'pause_reason' => $c->pause_reason,
            'scheduled_at' => $c->scheduled_at?->toIso8601String(),
            'started_at' => $c->started_at?->toIso8601String(),
            'completed_at' => $c->completed_at?->toIso8601String(),
            'failure_reason' => $c->failure_reason,
            'stats' => $this->campaigns->stats($c),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
