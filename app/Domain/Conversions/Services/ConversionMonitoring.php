<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\ConversionsAlert;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Monitoring\SentryReporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Conversions to Meta, platform monitoring (Super Admin only). Across every workspace:
 *  - success rate: sent ÷ (sent + failed + expired), test events left out
 *  - successful events in the last 15 days (test events included: they are real calls to Meta)
 *  - share of conversions that had a click ID (the rest are skipped "no_click_id")
 *  - send delay: from recording a conversion to Meta accepting it (median and 95th percentile)
 *  - failures, expiries and skips grouped by reason
 * Alerts go to Sentry when the 24-hour success rate drops below 90% (once there are enough events
 * to judge) or anything has been queued for more than 24 hours.
 */
final class ConversionMonitoring
{
    public const WINDOW_DAYS = 15;

    public const MIN_SUCCESS_RATE = 0.9;

    /** Below this many finished events in 24 hours the rate is not judged (one failure would alert). */
    public const MIN_EVENTS_FOR_ALERT = 20;

    public const MAX_QUEUED_HOURS = 24;

    /** The same alert is sent at most once in this many hours. */
    public const ALERT_EVERY_HOURS = 6;

    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return $this->context->bypass(function (): array {
            $since = now()->subDays(self::WINDOW_DAYS);
            $base = fn () => DB::table('conversion_events')->where('created_at', '>=', $since);

            $overall = $base()->where('is_test', false)->selectRaw("
                count(*) AS total,
                count(*) FILTER (WHERE status = 'sent') AS sent,
                count(*) FILTER (WHERE status = 'failed') AS failed,
                count(*) FILTER (WHERE status = 'expired') AS expired,
                count(*) FILTER (WHERE status = 'queued') AS queued,
                count(*) FILTER (WHERE status = 'skipped') AS skipped,
                count(*) FILTER (WHERE ad_click_id IS NOT NULL) AS with_click")->first();

            $delay = $base()->where('status', 'sent')->whereNotNull('sent_at')->selectRaw('
                percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM sent_at - created_at)) AS p50,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY extract(epoch FROM sent_at - created_at)) AS p95')->first();

            $workspaces = DB::table('conversion_events as e')->join('tenants as t', 't.id', '=', 'e.tenant_id')
                ->where('e.created_at', '>=', $since)->where('e.is_test', false)->groupBy('t.id', 't.name')
                ->selectRaw("t.id, t.name,
                    count(*) FILTER (WHERE e.status = 'sent') AS sent,
                    count(*) FILTER (WHERE e.status IN ('failed', 'expired')) AS failed,
                    count(*) FILTER (WHERE e.status = 'queued') AS queued,
                    count(*) FILTER (WHERE e.status = 'skipped') AS skipped,
                    count(*) FILTER (WHERE e.ad_click_id IS NOT NULL) AS with_click,
                    count(*) AS total")
                ->orderByDesc('total')->get()
                ->map(fn ($r) => [
                    'id' => $r->id, 'name' => $r->name,
                    'sent' => (int) $r->sent, 'failed' => (int) $r->failed, 'queued' => (int) $r->queued, 'skipped' => (int) $r->skipped,
                    'success_rate' => self::rate((int) $r->sent, (int) $r->failed),
                    'click_id_share' => (int) $r->total > 0 ? round((int) $r->with_click / (int) $r->total, 4) : null,
                ])->all();

            $reasons = $base()->where('is_test', false)->whereIn('status', ['failed', 'expired', 'skipped'])
                ->groupBy('status', 'error_reason')->selectRaw('status, error_reason AS reason, count(*) AS count')
                ->orderByDesc('count')->limit(30)->get()
                ->map(fn ($r) => ['status' => $r->status, 'reason' => $r->reason ?? 'No reason recorded', 'count' => (int) $r->count])->all();

            $oldestQueued = DB::table('conversion_events')->where('status', 'queued')->min('created_at');
            $successful15d = $base()->where('status', 'sent')->count();

            return [
                'window_days' => self::WINDOW_DAYS,
                'success_rate' => self::rate((int) $overall->sent, (int) $overall->failed + (int) $overall->expired),
                'successful_events' => $successful15d,
                'counts' => ['total' => (int) $overall->total, 'sent' => (int) $overall->sent, 'failed' => (int) $overall->failed,
                    'expired' => (int) $overall->expired, 'queued' => (int) $overall->queued, 'skipped' => (int) $overall->skipped],
                'click_id_share' => (int) $overall->total > 0 ? round((int) $overall->with_click / (int) $overall->total, 4) : null,
                'send_delay_seconds' => ['p50' => $delay?->p50 !== null ? (int) round((float) $delay->p50) : null, 'p95' => $delay?->p95 !== null ? (int) round((float) $delay->p95) : null],
                'oldest_queued_at' => $oldestQueued !== null ? Carbon::parse((string) $oldestQueued)->toIso8601String() : null,
                'workspaces' => $workspaces,
                'reasons' => $reasons,
                'last_24h' => $this->last24h(),
            ];
        });
    }

    /** @return array{sent: int, failed: int, success_rate: ?float} */
    private function last24h(): array
    {
        $r = DB::table('conversion_events')->where('is_test', false)->where('updated_at', '>=', now()->subDay())
            ->selectRaw("count(*) FILTER (WHERE status = 'sent') AS sent, count(*) FILTER (WHERE status IN ('failed', 'expired')) AS failed")->first();

        return ['sent' => (int) $r->sent, 'failed' => (int) $r->failed, 'success_rate' => self::rate((int) $r->sent, (int) $r->failed)];
    }

    private static function rate(int $ok, int $bad): ?float
    {
        return $ok + $bad === 0 ? null : round($ok / ($ok + $bad), 4);
    }

    /**
     * Checks the two alert rules and reports to Sentry (and the log). Returns the alerts raised.
     *
     * @return list<string>
     */
    public function alert(): array
    {
        $alerts = $this->context->bypass(function (): array {
            $alerts = [];
            $day = $this->last24h();
            if ($day['sent'] + $day['failed'] >= self::MIN_EVENTS_FOR_ALERT && $day['success_rate'] !== null && $day['success_rate'] < self::MIN_SUCCESS_RATE) {
                $alerts['success_rate'] = sprintf('Conversions to Meta: success rate %.1f%% in the last 24 hours (%d sent, %d failed or expired), below 90%%.',
                    $day['success_rate'] * 100, $day['sent'], $day['failed']);
            }
            $stuck = DB::table('conversion_events')->where('status', 'queued')->where('created_at', '<', now()->subHours(self::MAX_QUEUED_HOURS))->count();
            if ($stuck > 0) {
                $alerts['queued'] = sprintf('Conversions to Meta: %d event(s) queued for more than 24 hours.', $stuck);
            }

            return $alerts;
        });

        foreach ($alerts as $kind => $message) {
            Log::warning($message, ['alert' => 'conversions.'.$kind]);
            if (Cache::add('conversions:alert:'.$kind, 1, now()->addHours(self::ALERT_EVERY_HOURS))) {
                app(SentryReporter::class)->capture($kind === 'queued' ? ConversionsAlert::queued($message) : ConversionsAlert::successRate($message));
            }
        }

        return array_values($alerts);
    }
}
