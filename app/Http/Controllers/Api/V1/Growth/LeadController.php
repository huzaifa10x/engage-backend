<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Growth;

use App\Domain\Access\Permission;
use App\Domain\Audit\AuditLogger;
use App\Domain\Growth\GrowthAccess;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Growth\Services\Acquisition;
use App\Domain\Growth\Services\LeadQuery;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LeadResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Growth Hub › Leads (Developer Handover §I.4, Technical Specification v1.2 §6): the CRM's WhatsApp
 * leads with a read-only join to their source. Full tier only (CN-01: Starter has the chip only).
 * Agents see their own leads, never codes, click IDs or costs; Unmatched clicks and Export need
 * growth.view.
 */
final class LeadController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly LeadQuery $leads) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensureFull();
        $f = $request->validate(LeadQuery::RULES + ['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $rows = $this->leads->filtered($f)
            ->with(['contact.stage', 'phoneNumber', 'assignee.user'])
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->cursorPaginate((int) ($f['per_page'] ?? 50));

        // Tab counts for the same filters.
        $counts = [];
        foreach (['all', 'new', 'unattributed'] as $tab) {
            $q = $this->leads->filtered($f, withTab: false);
            $this->leads->tab($q, $tab);
            $counts[$tab] = $q->count();
        }

        return LeadResource::collection($rows)->additional(['meta' => ['counts' => $counts, 'sees_all' => $this->leads->seesAll()]]);
    }

    /** KPI row: WhatsApp leads in 30 days and how many of them have a confirmed source. */
    public function summary(): JsonResponse
    {
        $this->ensureFull();
        $tenant = $this->context->tenant();
        $startedAt = GrowthSettings::for($tenant)->startedAt;
        $since = now()->subDays(30);

        // New enquiries after tracking began, counted once per contact.
        $count = function (?callable $extra = null) use ($since, $startedAt): int {
            return $this->leads->visible()->whereHas('contact', function ($c) use ($since, $startedAt, $extra) {
                LeadQuery::tracked($c, $startedAt)->where('contacts.created_at', '>=', $since);
                if ($extra !== null) {
                    $extra($c);
                }
            })->distinct()->count('contact_id');
        };

        $leads = $count();
        $confirmed = $count(fn ($c) => $c->whereNotNull('acquisition_original'));
        $meta = $count(fn ($c) => $c->whereRaw("acquisition_original->>'source' = 'meta_ad'"));
        $instagram = $count(fn ($c) => $c->whereRaw("acquisition_original->>'source' = 'meta_ad'")->whereRaw("acquisition_original->>'platform' = 'instagram'"));
        $webLost = $count(fn ($c) => $c->whereRaw("acquisition_original->>'type' = 'website_unregistered'"));

        $clicks = null;
        if ($this->leads->seesAll()) {
            $base = DB::table('growth_click_events')->where('tenant_id', $tenant->id)->where('received_at', '>=', $since)->whereIn('status', ['registered', 'expired']);
            $clicks = [
                'total' => (clone $base)->count(),
                'pending' => (clone $base)->where('status', 'registered')->where('received_at', '>=', now()->subDay())->count(),
            ];
        }

        return response()->json(['data' => [
            'leads' => $leads,
            'confirmed' => $confirmed,
            'confirmed_rate' => $leads > 0 ? (int) round($confirmed / $leads * 100) : null,
            'meta' => $meta,
            'meta_instagram' => $instagram,
            'website_lost' => $webLost,
            'unmatched_clicks' => $clicks,
            'sees_all' => $this->leads->seesAll(),
        ]]);
    }

    /**
     * Unmatched clicks: WhatsApp taps on a tracked website that never became a chat. Click events only,
     * never contacts. Pending for 24 hours, then Unattributed. No click IDs are returned.
     */
    public function unmatchedClicks(): JsonResponse
    {
        $this->ensureFull();
        abort_unless($this->leads->seesAll(), 403);

        $rows = DB::table('growth_click_events')
            ->where('tenant_id', $this->context->tenant()->id)
            ->whereIn('status', ['registered', 'expired'])
            ->where('received_at', '>=', now()->subDays(30))
            ->orderByDesc('received_at')->limit(200)
            ->get(['id', 'received_at', 'page_path', 'campaign_json', 'status']);

        return response()->json(['data' => $rows->map(function (object $r) {
            $at = Carbon::parse((string) $r->received_at);
            $pending = $r->status === 'registered' && $at->gt(now()->subDay());
            $campaign = (array) json_decode((string) $r->campaign_json, true);

            return [
                'id' => 'clk_'.substr((string) $r->id, 0, 6),
                'received_at' => $at->toIso8601String(),
                'page' => $r->page_path,
                'source' => self::clickSource($campaign),
                'status' => $pending ? 'pending' : 'unattributed',
                'note' => $pending ? 'Waiting up to 24 h for a message' : 'No message within 24 h',
            ];
        })->values()]);
    }

    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        $this->ensureFull();
        abort_unless($this->leads->seesAll(), 403);
        $f = $request->validate(LeadQuery::RULES);

        $query = $this->leads->filtered($f)->with(['contact.stage', 'phoneNumber', 'assignee.user'])->orderByDesc('last_message_at');
        $audit->record('growth.leads.exported', null, meta: array_filter($f));

        $tenant = $this->context->tenant();
        $membership = $this->context->membership();
        $zone = $tenant->timezone ?: 'UTC';
        $settings = GrowthSettings::for($tenant);

        return response()->streamDownload(fn () => $this->context->run($tenant, function () use ($query, $zone, $settings): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Name', 'Phone', 'Source', 'Campaign', 'Match status', 'Number', 'First contact', 'Agent', 'Stage', 'Last activity'], escape: '');
            $query->chunk(500, function ($chunk) use ($out, $zone, $settings): void {
                foreach ($chunk as $c) {
                    /** @var Conversation $c */
                    /** @var Contact $contact */
                    $contact = $c->contact;
                    $a = Acquisition::present($contact, $settings, false, true);
                    // Leading = + - @ would be run as a formula by spreadsheet apps.
                    $safe = fn (?string $v) => $v !== null && preg_match('/^[=+\-@]/', $v) === 1 ? "'".$v : (string) $v;
                    fputcsv($out, [
                        $safe($contact->displayName()),
                        $contact->getAttribute('wa_id') ? '+'.$contact->getAttribute('wa_id') : '',
                        $a['source']['name'] ?? '',
                        $safe($a['source']['campaign'] ?? null),
                        Acquisition::LABELS[$a['status'] ?? 'unattributed'] ?? '',
                        $c->phoneNumber->display_phone_number ?? '',
                        $contact->created_at?->setTimezone($zone)->format('Y-m-d H:i'),
                        $c->assignee->user->name ?? 'Unassigned',
                        $contact->stage->name ?? '',
                        $c->last_message_at?->setTimezone($zone)->format('Y-m-d H:i'),
                    ], escape: '');
                }
            });
            fclose($out);
        }, $membership), 'leads-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param array<string, mixed> $campaign */
    private static function clickSource(array $campaign): string
    {
        $utm = trim(implode(' · ', array_filter([$campaign['utm_source'] ?? null, $campaign['utm_campaign'] ?? null], fn ($v) => is_string($v) && $v !== '')));
        if ($utm !== '') {
            return $utm;
        }
        if (! empty($campaign['gclid']) || ! empty($campaign['gbraid']) || ! empty($campaign['wbraid'])) {
            return 'Google Ads';
        }

        return ! empty($campaign['fbclid']) ? 'Meta (fbclid)' : 'Direct';
    }

    private function ensureFull(): void
    {
        abort_unless(GrowthAccess::full($this->context->tenant()), 404);
        abort_unless($this->context->can(Permission::InboxView), 403);
    }
}
