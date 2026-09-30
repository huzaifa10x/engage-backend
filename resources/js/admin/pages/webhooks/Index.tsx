import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Card, Kpi, PageHeader, Pill, Select } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { dateTime, humanize, number } from '../../lib/format';
import type { Tone } from '../../types';

type Row = {
    id: string; field: string; waba_id: string | null; phone_number_id: string | null; process_status: string; attempts: number;
    last_error: string | null; received_at: string; processed_at: string | null; payload: string; tenant_id: string | null; tenant: string | null;
};
type Props = {
    rows: Row[];
    stats: Record<'total' | 'pending' | 'processed' | 'ignored' | 'deferred' | 'failed', number>;
    fields: { field: string; count: number }[];
    filters: { field?: string; status?: string; waba_id?: string };
    configured: { app: boolean; verify_token: boolean; callback_url: string };
};

const statusTone: Record<string, Tone> = { processed: 'good', deferred: 'info', ignored: 'grey', pending: 'warn', failed: 'bad' };

export default function WebhooksIndex({ rows, stats, fields, filters, configured }: Props) {
    const [open, setOpen] = useState<string | null>(null);
    const apply = (next: Record<string, string | undefined>) => router.get('/admin/webhooks', { ...filters, ...next }, { preserveState: true, replace: true });

    return (
        <AdminLayout title="Webhooks">
            <PageHeader
                title="Webhooks"
                description="Every verified event Meta sent us, stored before processing. Deferred events wait for a module that is not built yet and can be replayed later."
                actions={stats.failed > 0 ? <Button onClick={() => router.post('/admin/webhooks/replay-failed', {}, { preserveScroll: true })}>Retry failed events</Button> : undefined}
            />

            {(!configured.app || !configured.verify_token) && (
                <div className="mb-4 rounded-card border border-warn/30 bg-warn-bg px-4 py-3 text-[13.5px] text-warn">
                    Meta is not fully configured on this environment. Set META_APP_ID, META_APP_SECRET and META_WEBHOOK_VERIFY_TOKEN, then use{' '}
                    <span className="font-mono">{configured.callback_url}</span> as the callback URL in the App Dashboard.
                </div>
            )}

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Kpi label="Received (24h)" value={number(stats.total)} />
                <Kpi label="Processed" value={number(stats.processed)} hint={`${stats.ignored} ignored`} />
                <Kpi label="Deferred" value={number(stats.deferred)} hint="Stored for later modules" />
                <Kpi label="Failed" value={number(stats.failed)} hint={stats.pending ? `${stats.pending} waiting` : 'Nothing waiting'} />
            </div>

            <div className="mt-4 mb-3 flex flex-wrap items-center gap-2">
                <Select className="w-auto" value={filters.field ?? ''} onChange={(e) => apply({ field: e.target.value || undefined })} aria-label="Field">
                    <option value="">All fields</option>
                    {fields.map((f) => <option key={f.field} value={f.field}>{f.field} ({f.count})</option>)}
                </Select>
                <Select className="w-auto" value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value || undefined })} aria-label="Status">
                    <option value="">Any status</option>
                    {['processed', 'deferred', 'ignored', 'pending', 'failed'].map((s) => <option key={s} value={s}>{humanize(s)}</option>)}
                </Select>
                {filters.waba_id && <Button size="sm" onClick={() => apply({ waba_id: undefined })}>Showing WABA {filters.waba_id}. Show all</Button>}
            </div>

            <Card>
                <ul className="divide-y divide-line-2">
                    {rows.map((r) => (
                        <li key={r.id}>
                            <button className="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-canvas" onClick={() => setOpen(open === r.id ? null : r.id)} aria-expanded={open === r.id}>
                                <span className="w-56 shrink-0 font-mono text-[13px] font-semibold">{r.field}</span>
                                <span className="min-w-0 flex-1 truncate text-[13px] text-muted">
                                    {r.tenant ?? 'Unknown workspace'}{r.waba_id ? `, WABA ${r.waba_id}` : ''}
                                </span>
                                <Pill tone={statusTone[r.process_status] ?? 'grey'}>{humanize(r.process_status)}</Pill>
                                <span className="w-32 shrink-0 text-right text-[12.5px] text-muted">{dateTime(r.received_at)}</span>
                            </button>
                            {open === r.id && (
                                <div className="space-y-2 bg-canvas px-4 py-3">
                                    {r.last_error && <div className="rounded-lg border border-bad/20 bg-bad-bg px-3 py-2 font-mono text-[12px] text-bad">{r.last_error}</div>}
                                    <div className="text-[12px] text-muted">Attempts {r.attempts}, processed {dateTime(r.processed_at)}{r.phone_number_id ? `, phone number ID ${r.phone_number_id}` : ''}</div>
                                    <pre className="max-h-96 overflow-auto rounded-lg border border-line bg-white p-3 font-mono text-[12px]">{pretty(r.payload)}</pre>
                                </div>
                            )}
                        </li>
                    ))}
                    {rows.length === 0 && <li className="px-4 py-10 text-center text-muted">No webhooks in the last 30 days match these filters.</li>}
                </ul>
            </Card>
        </AdminLayout>
    );
}

function pretty(raw: string): string {
    try {
        return JSON.stringify(JSON.parse(raw), null, 2);
    } catch {
        return raw;
    }
}
