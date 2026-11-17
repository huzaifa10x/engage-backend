<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How long one incoming customer message takes, from the moment Meta's webhook reached us:
 *  - until it was processed (saved to the inbox, automations started);
 *  - until our automatic reply was created (a chatbot or auto-reply message in the same chat);
 *  - until Meta accepted that reply (our send call returned; recorded from 0076 on).
 * Average and slowest 5% (95th percentile), in milliseconds. Read-only.
 *
 * --split cuts the window in two (before / after a deploy), to compare.
 */
final class WebhookLatency extends Command
{
    protected $signature = 'engage:webhooks:latency
        {--from= : Start (default: 24 hours ago), e.g. "2026-11-17 08:00"}
        {--to= : End (default: now)}
        {--split= : Compare before and after this time (a deploy)}
        {--workspace= : Only this workspace (ID)}';

    protected $description = 'Incoming message timing: webhook received → processed → automatic reply → accepted by Meta (average and slowest 5%).';

    public function handle(TenantContext $context): int
    {
        $from = Carbon::parse($this->option('from') ?? now()->subDay())->utc();
        $to = Carbon::parse($this->option('to') ?? now())->utc();
        $split = $this->option('split') !== null ? Carbon::parse($this->option('split'))->utc() : null;

        $windows = $split !== null ? ['Before '.$split->toDateTimeString().' UTC' => [$from, $split], 'After' => [$split, $to]] : ['Window' => [$from, $to]];
        $rows = [];
        foreach ($windows as $label => [$start, $end]) {
            $r = $context->bypass(fn () => $this->measure($start, $end));
            $rows[] = [$label.' ('.$start->format('M j H:i').' → '.$end->format('M j H:i').')', $r->messages, '', '', ''];
            $rows[] = ['  Received → processed', $r->processed, $this->ms($r->processed_avg), $this->ms($r->processed_p95), ''];
            $rows[] = ['  Received → automatic reply created', $r->replies, $this->ms($r->reply_avg), $this->ms($r->reply_p95), ''];
            $rows[] = ['  Received → reply accepted by Meta', $r->accepted, $this->ms($r->accepted_avg), $this->ms($r->accepted_p95), $r->accepted === 0 ? 'recorded from 0076 on' : ''];
        }
        $this->table(['Incoming customer messages', 'Count', 'Average', 'Slowest 5%', ''], $rows);

        return self::SUCCESS;
    }

    private function measure(Carbon $start, Carbon $end): object
    {
        $workspace = $this->option('workspace');
        $sql = <<<'SQL'
            WITH w AS (
                SELECT l.tenant_id, l.received_at, l.processed_at, l.payload->'value'->'messages'->0->>'id' AS wamid
                FROM webhook_inbound_log l
                WHERE l.field = 'messages' AND l.received_at >= ? AND l.received_at < ? AND l.payload->'value'->'messages' IS NOT NULL
                  AND (?::uuid IS NULL OR l.tenant_id = ?::uuid)
            ), m AS (
                SELECT w.received_at, w.processed_at, i.tenant_id, i.conversation_id, i.created_at AS in_at
                FROM w JOIN messages i ON i.wamid = w.wamid AND i.direction = 'inbound' AND i.origin = 'customer'
            ), r AS (
                SELECT m.*, rep.created_at AS reply_at, rep.accepted_at
                FROM m LEFT JOIN LATERAL (
                    SELECT o.created_at, o.accepted_at FROM messages o
                    WHERE o.conversation_id = m.conversation_id AND o.direction = 'outbound' AND o.origin = 'automation'
                      AND o.created_at >= m.in_at AND o.created_at < m.in_at + interval '10 minutes'
                    ORDER BY o.created_at LIMIT 1
                ) rep ON true
            )
            SELECT count(*) AS messages,
                count(processed_at) AS processed,
                avg(extract(epoch FROM processed_at - received_at)) * 1000 AS processed_avg,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY extract(epoch FROM processed_at - received_at)) * 1000 AS processed_p95,
                count(reply_at) AS replies,
                avg(extract(epoch FROM reply_at - received_at)) * 1000 AS reply_avg,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY extract(epoch FROM reply_at - received_at)) * 1000 AS reply_p95,
                count(accepted_at) AS accepted,
                avg(extract(epoch FROM accepted_at - received_at)) * 1000 AS accepted_avg,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY extract(epoch FROM accepted_at - received_at)) * 1000 AS accepted_p95
            FROM r
            SQL;
        $r = DB::selectOne($sql, [$start, $end, $workspace, $workspace]);
        foreach (['messages', 'processed', 'replies', 'accepted'] as $count) {
            $r->{$count} = (int) $r->{$count};
        }

        return $r;
    }

    private function ms(mixed $value): string
    {
        return $value === null ? '—' : number_format((float) $value).' ms';
    }
}
