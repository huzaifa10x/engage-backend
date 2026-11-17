<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Models\Campaign;
use Illuminate\Support\Facades\DB;

/**
 * A/B test on a campaign: two variants (A = the campaign's own template, B = a second approved
 * template, or the same template sent later), split randomly and evenly so a contact receives only
 * one. Results are counted from the message logs of the test recipients only.
 *
 * A winner is declared only when both variants reached the minimum sample (messages sent) and the
 * gap between their goal rates is clear: a two-proportion z-test at 95% confidence.
 */
final class AbTest
{
    public const KINDS = ['template', 'button', 'send_time'];

    public const GOALS = ['reply', 'click', 'lead', 'purchase'];

    public const DEFAULT_MIN_SAMPLE = 200;

    /** 95% two-sided. */
    private const Z = 1.96;

    /** How long after the campaign message a reply, click, lead or purchase still counts for it. */
    public const REPLY_HOURS = 72;

    public const CONVERSION_DAYS = 7;

    public static function enabled(Campaign $campaign): bool
    {
        return is_array($campaign->ab_test) && ($campaign->ab_test['kind'] ?? null) !== null;
    }

    /**
     * Gives every pending recipient a variant: shuffled once, then alternately A and B, so the split is
     * even (500/500 for 1,000). With a 20% test, the first 20% of the shuffled list are tested and the
     * others are held for the winner.
     */
    public function assign(Campaign $campaign): void
    {
        $ab = (array) $campaign->ab_test;
        $total = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->where('status', 'pending')->count();
        $percent = (int) ($ab['test_percent'] ?? 100);
        // An even number in the test whenever possible, and never more than the list.
        $testCount = $percent >= 100 ? $total : min($total, 2 * (int) ceil($total * $percent / 200));
        $delayed = ($ab['kind'] ?? null) === 'send_time' ? now()->addHours(max(1, (int) ($ab['b_delay_hours'] ?? 1))) : null;

        DB::update(
            "WITH ranked AS (
                SELECT id, row_number() OVER (ORDER BY random()) - 1 AS rn FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending'
            )
            UPDATE campaign_recipients cr SET
                variant = CASE WHEN r.rn < ? THEN (CASE WHEN r.rn % 2 = 0 THEN 'A' ELSE 'B' END) END,
                phase = CASE WHEN r.rn < ? THEN 'test' ELSE 'rest' END,
                status = CASE WHEN r.rn < ? THEN 'pending' ELSE 'held' END,
                not_before = CASE WHEN r.rn < ? AND r.rn % 2 = 1 THEN ?::timestamptz END,
                updated_at = now()
            FROM ranked r WHERE cr.id = r.id",
            [$campaign->id, $testCount, $testCount, $testCount, $testCount, $delayed?->toIso8601String()],
        );
    }

    /**
     * Results per variant (test recipients only) and the verdict.
     *
     * @return array<string, mixed>
     */
    public function results(Campaign $campaign): array
    {
        $ab = (array) $campaign->ab_test;
        $goal = in_array($ab['goal'] ?? null, self::GOALS, true) ? (string) $ab['goal'] : 'reply';
        $min = max(1, (int) ($ab['min_sample'] ?? self::DEFAULT_MIN_SAMPLE));

        $rows = DB::table('campaign_recipients as cr')->leftJoin('messages as m', 'm.id', '=', 'cr.message_id')
            ->where('cr.campaign_id', $campaign->id)->where('cr.phase', 'test')->whereNotNull('cr.variant')
            ->groupBy('cr.variant')
            ->selectRaw("cr.variant,
                count(*) AS recipients,
                count(*) FILTER (WHERE cr.status IN ('pending','held')) AS waiting,
                count(*) FILTER (WHERE m.status IN ('accepted','sent','delivered','read')) AS sent,
                count(*) FILTER (WHERE m.status IN ('delivered','read')) AS delivered,
                count(*) FILTER (WHERE m.status = 'read') AS read,
                count(*) FILTER (WHERE m.status IN ('accepted','sent','delivered','read') AND EXISTS (
                    SELECT 1 FROM messages reply WHERE reply.conversation_id = m.conversation_id AND reply.direction = 'inbound'
                    AND reply.created_at > m.created_at AND reply.created_at <= m.created_at + make_interval(hours => ?))) AS replied,
                count(*) FILTER (WHERE m.status IN ('accepted','sent','delivered','read') AND m.wamid IS NOT NULL AND EXISTS (
                    SELECT 1 FROM messages tap WHERE tap.conversation_id = m.conversation_id AND tap.direction = 'inbound'
                    AND tap.context_wamid = m.wamid AND tap.type IN ('button','interactive'))) AS clicked,
                count(*) FILTER (WHERE m.status IN ('accepted','sent','delivered','read') AND EXISTS (
                    SELECT 1 FROM conversion_events ce WHERE ce.contact_id = cr.contact_id AND ce.event_name = 'LeadSubmitted' AND ce.is_test = false
                    AND ce.event_time > m.created_at AND ce.event_time <= m.created_at + make_interval(days => ?))) AS leads,
                count(*) FILTER (WHERE m.status IN ('accepted','sent','delivered','read') AND EXISTS (
                    SELECT 1 FROM conversion_events ce WHERE ce.contact_id = cr.contact_id AND ce.event_name = 'Purchase' AND ce.is_test = false
                    AND ce.event_time > m.created_at AND ce.event_time <= m.created_at + make_interval(days => ?))) AS purchases",
                [self::REPLY_HOURS, self::CONVERSION_DAYS, self::CONVERSION_DAYS])
            ->get()->keyBy('variant');

        $metric = ['reply' => 'replied', 'click' => 'clicked', 'lead' => 'leads', 'purchase' => 'purchases'][$goal];
        $variants = [];
        foreach (['A', 'B'] as $key) {
            $r = $rows->get($key);
            $v = ['variant' => $key];
            foreach (['recipients', 'waiting', 'sent', 'delivered', 'read', 'replied', 'clicked', 'leads', 'purchases'] as $f) {
                $v[$f] = (int) ($r->{$f} ?? 0);
            }
            $v['rate'] = $v['sent'] > 0 ? round($v[$metric] / $v['sent'], 4) : null;
            $variants[$key] = $v;
        }

        return [
            'goal' => $goal,
            'metric' => $metric,
            'min_sample' => $min,
            'variants' => array_values($variants),
            // B minus A, in percentage points of the goal rate.
            'difference' => $variants['A']['rate'] !== null && $variants['B']['rate'] !== null ? round(($variants['B']['rate'] - $variants['A']['rate']) * 100, 2) : null,
        ] + $this->verdict($variants['A']['sent'], $variants['A'][$metric], $variants['B']['sent'], $variants['B'][$metric], $min);
    }

    /**
     * @return array{state: string, winner: ?string, message: string, z: ?float}
     */
    public function verdict(int $nA, int $xA, int $nB, int $xB, int $min): array
    {
        if ($nA < $min || $nB < $min) {
            return ['state' => 'not_enough_data', 'winner' => null, 'z' => null,
                'message' => "Not enough data yet: each variant needs {$min} messages sent (A: {$nA}, B: {$nB})."];
        }
        $p = ($xA + $xB) / ($nA + $nB);
        $se = sqrt($p * (1 - $p) * (1 / $nA + 1 / $nB));
        if ($se <= 0.0) {
            return ['state' => 'not_enough_data', 'winner' => null, 'z' => null, 'message' => 'Not enough data yet: no difference between the variants so far.'];
        }
        $z = ($xB / $nB - $xA / $nA) / $se;
        if (abs($z) < self::Z) {
            return ['state' => 'not_enough_data', 'winner' => null, 'z' => round($z, 2),
                'message' => 'Not enough data yet: the gap between A and B is not clear (95% confidence).'];
        }
        $winner = $z > 0 ? 'B' : 'A';

        return ['state' => 'winner', 'winner' => $winner, 'z' => round($z, 2), 'message' => "Variant {$winner} wins (95% confidence)."];
    }
}
