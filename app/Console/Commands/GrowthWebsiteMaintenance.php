<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Growth\Website\WebsiteMatcher;
use App\Domain\Growth\Website\WebsiteSource;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Notifications\InternalAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Growth Hub website tracking upkeep (Developer Handover step 10; Technical Specification v1.2 §9,
 * §10), every five minutes:
 *  1. codes still unregistered after 24 h + 48 h → "Website · details unavailable";
 *  2. taps never followed by a message after the grace period → expired (Unmatched clicks);
 *  3. daily totals per site, day and source rebuilt for the last days (late matches correct them),
 *     always before raw events are deleted; no personal data in them;
 *  4. retention: unmatched raw taps 30 days, matched taps 90 days, evidence 12 months (the
 *     contact's source, with its Google / Meta click IDs, stays on the CRM record);
 *  5. alerts for the 10X Engage team: match rate below 95 % (≥ 30 eligible messages in 24 h),
 *     reference collisions, a tracker that stopped reporting on a site with traffic.
 */
final class GrowthWebsiteMaintenance extends Command
{
    protected $signature = 'engage:growth:website-maintenance {--days=4 : days of daily totals to rebuild}';

    protected $description = 'Growth Hub website tracking: finalize pending codes, expire taps, daily totals, retention and alerts.';

    public function handle(TenantContext $context, WebsiteMatcher $matcher): int
    {
        $tenants = $context->bypass(fn () => Tenant::query()->whereIn('id', DB::table('growth_sites')->select('tenant_id')->distinct())
            ->orWhereIn('id', DB::table('growth_pending_references')->where('status', 'pending')->select('tenant_id')->distinct())->get());

        foreach ($tenants as $tenant) {
            try {
                $context->run($tenant, function () use ($tenant, $matcher) {
                    $matcher->finalizePending($tenant);
                    DB::table('growth_click_events')->where('tenant_id', $tenant->id)->where('status', 'registered')
                        ->where('received_at', '<', now()->subHours(WebsiteMatcher::GRACE_HOURS))->update(['status' => 'expired']);
                    $this->rollup($tenant, max(1, (int) $this->option('days')));
                    $this->purge($tenant);
                    $this->alerts($tenant);
                });
            } catch (\Throwable $e) {
                Log::warning('Growth Hub website maintenance failed for a workspace.', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }

        return self::SUCCESS;
    }

    private function rollup(Tenant $tenant, int $days): void
    {
        $tz = $tenant->timezone ?: 'UTC';
        $fromLocal = now($tz)->subDays($days - 1)->startOfDay();
        $domains = DB::table('growth_sites')->where('tenant_id', $tenant->id)->pluck('domain', 'id');
        $totals = [];

        DB::table('growth_click_events')->where('tenant_id', $tenant->id)->where('received_at', '>=', $fromLocal->copy()->utc())
            ->orderBy('id')->select(['id', 'site_id', 'received_at', 'campaign_json', 'referrer_host', 'status'])
            ->chunk(2000, function ($rows) use (&$totals, $tz, $domains) {
                foreach ($rows as $r) {
                    $date = Carbon::parse((string) $r->received_at)->setTimezone($tz)->toDateString();
                    $bucket = WebsiteSource::classify((array) json_decode((string) $r->campaign_json, true), $r->referrer_host, $domains[$r->site_id] ?? null)['source'];
                    $key = $r->site_id.'|'.$date.'|'.$bucket;
                    $totals[$key] ??= ['accepted_clicks' => 0, 'filtered_clicks' => 0, 'expired_unmatched' => 0, 'confirmed_reference_matches' => 0];
                    match ($r->status) {
                        'filtered' => $totals[$key]['filtered_clicks']++,
                        default => $totals[$key]['accepted_clicks']++,
                    };
                    if ($r->status === 'expired') {
                        $totals[$key]['expired_unmatched']++;
                    }
                    if ($r->status === 'matched') {
                        $totals[$key]['confirmed_reference_matches']++;
                    }
                }
            });

        DB::transaction(function () use ($tenant, $fromLocal, $totals) {
            DB::table('growth_daily_rollups')->where('tenant_id', $tenant->id)->where('local_date', '>=', $fromLocal->toDateString())->delete();
            foreach ($totals as $key => $t) {
                [$site, $date, $bucket] = explode('|', $key);
                DB::table('growth_daily_rollups')->insert(['tenant_id' => $tenant->id, 'site_id' => $site, 'local_date' => $date, 'source_bucket' => $bucket] + $t);
            }
        });
    }

    private function purge(Tenant $tenant): void
    {
        DB::table('growth_click_events')->where('tenant_id', $tenant->id)->whereIn('status', ['registered', 'expired', 'filtered'])
            ->where('received_at', '<', now()->subDays(30))->delete();
        DB::table('growth_click_events')->where('tenant_id', $tenant->id)->where('status', 'matched')
            ->where('received_at', '<', now()->subDays(90))->delete();
        DB::table('growth_pending_references')->where('tenant_id', $tenant->id)->where('status', '!=', 'pending')
            ->where('received_at', '<', now()->subDays(30))->delete();
        DB::table('growth_evidence')->where('tenant_id', $tenant->id)->where('occurred_at', '<', now()->subMonths(12))->delete();
    }

    private function alerts(Tenant $tenant): void
    {
        $since = now()->subDay();
        $matched = DB::table('growth_evidence')->where('tenant_id', $tenant->id)->where('evidence_type', 'website_reference')
            ->where('confidence', 'confirmed')->where('created_at', '>=', $since)->count();
        $waiting = DB::table('growth_pending_references')->where('tenant_id', $tenant->id)->where('received_at', '>=', $since)
            ->whereIn('status', ['pending', 'unregistered', 'expired'])->count();
        $eligible = $matched + $waiting;
        if ($eligible >= 30 && $matched / $eligible < 0.95) {
            $this->teamAlert($tenant, 'match_rate', 'Website code match rate below 95 %', ['eligible_24h' => $eligible, 'matched_24h' => $matched]);
        }

        $collisions = (int) Cache::get('growth:collisions:'.now()->toDateString(), 0);
        if ($collisions > 0) {
            $this->teamAlert($tenant, 'collisions', 'Website reference collisions today', ['collisions' => $collisions]);
        }

        $quiet = DB::table('growth_site_health as h')->join('growth_sites as s', 's.id', '=', 'h.site_id')
            ->where('h.tenant_id', $tenant->id)->where('s.enabled', true)
            ->where('h.last_click_at', '>=', now()->subDays(7))->where('h.sdk_last_seen_at', '<', now()->subHours(48))
            ->pluck('s.domain')->all();
        if ($quiet !== []) {
            $this->teamAlert($tenant, 'tracker_absent', 'Website tracker not seen for 48 h on a site with traffic', ['sites' => $quiet]);
        }
    }

    /** @param array<string, mixed> $detail */
    private function teamAlert(Tenant $tenant, string $code, string $title, array $detail): void
    {
        if (! Cache::add("growth:alert:{$tenant->id}:{$code}:".now()->toDateString(), 1, 86400)) {
            return; // once a day per workspace and alert
        }
        $context = ['tenant_id' => $tenant->id, 'workspace' => $tenant->name] + $detail;
        Log::warning('Growth Hub: '.$title, $context);
        $to = config('engage.alerts.email');
        if (is_string($to) && $to !== '') {
            Notification::route('mail', $to)->notify(new InternalAlertNotification("Growth Hub: {$title} ({$tenant->name})", $title.'.', $context));
        }
    }
}
