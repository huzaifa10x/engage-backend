<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Growth;

use App\Domain\Access\Permission;
use App\Domain\Audit\AuditLogger;
use App\Domain\Growth\GrowthAccess;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Growth\Services\Acquisition;
use App\Domain\Growth\Services\LeadQuery;
use App\Domain\Growth\Website\WebsiteSource;
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
        foreach (['all', 'new', 'returning'] as $tab) {
            $q = $this->leads->filtered($f, withTab: false);
            $this->leads->tab($q, $tab);
            $counts[$tab] = $q->count();
        }

        return LeadResource::collection($rows)->additional(['meta' => ['counts' => $counts, 'sees_all' => $this->leads->seesAll()]]);
    }

    /**
     * KPI row, counted exactly like the list: ad leads with a paid touch in the last 30 days (the
     * "All leads" tab with the default period shows the same number). New customers: their first
     * message came from the ad; returning: they were already customers (or wrote before) and came
     * back through an ad.
     */
    public function summary(): JsonResponse
    {
        $this->ensureFull();
        $tenant = $this->context->tenant();
        $startedAt = GrowthSettings::for($tenant)->startedAt;
        $since = now()->subDays(30);
        $paid = "'".implode("','", Acquisition::PAID_SOURCES)."'";
        // The touch counted for a lead: its latest paid touch.
        $touch = 'coalesce(acquisition_latest, acquisition_original)';

        $count = function (?callable $extra = null): int {
            $q = $this->leads->filtered(['days' => 30], withTab: false);
            if ($extra !== null) {
                $q->whereHas('contact', $extra);
            }

            return $q->count();
        };

        $leads = $count();
        // People, not chats or taps: the same rule as the New tab.
        $new = $count(fn ($c) => LeadQuery::newLead($c, $startedAt));
        $posts = $count(fn ($c) => $c->whereRaw("{$touch}->>'source_type' = 'post'"));
        $instagram = $count(fn ($c) => $c->whereRaw("{$touch}->>'platform' = 'instagram'"));
        // Website taps whose details were lost (valid code, tap never registered): not leads (no
        // campaign is known), counted for the website card only.
        $webLost = DB::table('contacts')->where('tenant_id', $tenant->id)->whereNull('deleted_at')
            ->whereRaw("acquisition_original->>'type' = 'website_unregistered'")
            ->whereRaw("(acquisition_original->>'occurred_at')::timestamptz >= ?", [$since])->count();
        // Website cards show only once the workspace tracks a website.
        $websites = DB::table('growth_sites')->where('tenant_id', $tenant->id)->count();

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
            'new_customers' => $new,
            'returning' => $leads - $new,
            'meta_instagram' => $instagram,
            'meta_posts' => $posts, // of the Meta leads, those from boosted posts
            'website_lost' => $websites > 0 ? $webLost : null,
            'unmatched_clicks' => $websites > 0 ? $clicks : null,
            'websites' => $websites,
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
            ->get(['id', 'site_id', 'received_at', 'page_path', 'campaign_json', 'referrer_host', 'status']);
        $domains = DB::table('growth_sites')->where('tenant_id', $this->context->tenant()->id)->pluck('domain', 'id');

        return response()->json(['data' => $rows->map(function (object $r) use ($domains) {
            $at = Carbon::parse((string) $r->received_at);
            $pending = $r->status === 'registered' && $at->gt(now()->subDay());
            $campaign = (array) json_decode((string) $r->campaign_json, true);

            return [
                'id' => 'clk_'.substr((string) $r->id, 0, 6),
                'received_at' => $at->toIso8601String(),
                'site' => $domains[$r->site_id] ?? null,
                'page' => $r->page_path,
                'source' => WebsiteSource::classify($campaign, $r->referrer_host, $domains[$r->site_id] ?? null)['label'],
                'campaign' => isset($campaign['utm_campaign']) ? (string) $campaign['utm_campaign'] : null,
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

    private function ensureFull(): void
    {
        abort_unless(GrowthAccess::full($this->context->tenant()), 404);
        abort_unless($this->context->can(Permission::InboxView), 403);
    }
}
