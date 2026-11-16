import { Card, Kpi, PageHeader, Pill, Table } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { humanize, number, relative } from '../../lib/format';
import type { Tone } from '../../types';

type Stats = {
    window_days: number;
    success_rate: number | null;
    successful_events: number;
    counts: Record<'total' | 'sent' | 'failed' | 'expired' | 'queued' | 'skipped', number>;
    click_id_share: number | null;
    send_delay_seconds: { p50: number | null; p95: number | null };
    oldest_queued_at: string | null;
    workspaces: { id: string; name: string; sent: number; failed: number; queued: number; skipped: number; success_rate: number | null; click_id_share: number | null }[];
    reasons: { status: string; reason: string; count: number }[];
    last_24h: { sent: number; failed: number; success_rate: number | null };
};

const pct = (r: number | null) => (r === null ? '—' : `${(r * 100).toFixed(1)}%`);
const rateTone = (r: number | null): Tone => (r === null ? 'grey' : r >= 0.9 ? 'good' : 'bad');
const duration = (s: number | null) => (s === null ? '—' : s < 90 ? `${s}s` : s < 5400 ? `${Math.round(s / 60)} min` : `${(s / 3600).toFixed(1)} h`);
const statusTone: Record<string, Tone> = { failed: 'bad', expired: 'warn', skipped: 'grey' };

export default function ConversionsIndex({ stats }: { stats: Stats }) {
    const stuck = stats.oldest_queued_at !== null && Date.now() - new Date(stats.oldest_queued_at).getTime() > 24 * 3600 * 1000;

    return (
        <AdminLayout title="Conversions to Meta">
            <PageHeader
                title="Conversions to Meta"
                description={`Conversion events sent to Meta for every workspace, last ${stats.window_days} days. Test events are left out of rates. Sentry is alerted when the 24-hour success rate drops below 90% or an event waits more than 24 hours.`}
            />

            {(stuck || (stats.last_24h.success_rate !== null && stats.last_24h.success_rate < 0.9)) && (
                <div role="alert" className="mb-4 rounded-card border border-bad/30 bg-bad-bg px-4 py-3 text-[13.5px] text-bad">
                    {stuck && <p>Events have been queued for more than 24 hours (oldest {relative(stats.oldest_queued_at)}).</p>}
                    {stats.last_24h.success_rate !== null && stats.last_24h.success_rate < 0.9 && <p>Success rate in the last 24 hours is {pct(stats.last_24h.success_rate)}.</p>}
                </div>
            )}

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                <Kpi label="Success rate" value={pct(stats.success_rate)} hint={`24h: ${pct(stats.last_24h.success_rate)}`} />
                <Kpi label={`Successful events (${stats.window_days} days)`} value={number(stats.successful_events)} hint="test events included" />
                <Kpi label="Had a click ID" value={pct(stats.click_id_share)} hint={`${number(stats.counts.total)} conversions recorded`} />
                <Kpi label="Send delay" value={duration(stats.send_delay_seconds.p50)} hint={`95% within ${duration(stats.send_delay_seconds.p95)}`} />
                <Kpi label="Waiting" value={number(stats.counts.queued)} hint={stats.oldest_queued_at ? `oldest ${relative(stats.oldest_queued_at)}` : 'nothing waiting'} />
            </div>

            <Card title="Per workspace" className="mt-4">
                <Table head={['Workspace', 'Success rate', 'Sent', 'Failed or expired', 'Waiting', 'Skipped', 'Had a click ID']} empty="No conversion events yet.">
                    {stats.workspaces.map((w) => (
                        <tr key={w.id}>
                            <td className="font-semibold">{w.name}</td>
                            <td><Pill tone={rateTone(w.success_rate)}>{pct(w.success_rate)}</Pill></td>
                            <td className="tabular-nums">{number(w.sent)}</td>
                            <td className="tabular-nums">{number(w.failed)}</td>
                            <td className="tabular-nums">{number(w.queued)}</td>
                            <td className="tabular-nums">{number(w.skipped)}</td>
                            <td className="tabular-nums">{pct(w.click_id_share)}</td>
                        </tr>
                    ))}
                </Table>
            </Card>

            <Card title="Failures by reason" className="mt-4">
                <Table head={['Status', 'Reason', 'Events']} empty="No failures, expiries or skips.">
                    {stats.reasons.map((r) => (
                        <tr key={`${r.status}:${r.reason}`}>
                            <td><Pill tone={statusTone[r.status] ?? 'grey'}>{humanize(r.status)}</Pill></td>
                            <td className="text-[13px]">{r.reason}</td>
                            <td className="tabular-nums">{number(r.count)}</td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </AdminLayout>
    );
}
