import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, Card, Input, Kpi, PageHeader, Pagination, Pill, Select, Table } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { dateTime, humanize, number, relative, statusTone } from '../../lib/format';
import { qualityLabel, qualityTone, tierLabel } from '../../lib/whatsapp';
import type { Paginated, SharedProps } from '../../types';

type Row = {
    id: string; display_phone_number: string | null; verified_name: string | null; quality_rating: string | null;
    messaging_limit_tier: string | null; throughput_level: string | null; status: string; onboarding_type: string;
    coexistence_status: string; name_status: string | null; last_synced_at: string | null;
    tenant_id: string; tenant: string; waba_id: string; ban_state: string | null; account_review_status: string | null;
};
type Event = { id: string; event_type: string; old_value: string | null; new_value: string | null; occurred_at: string; tenant_id: string; tenant: string; display_phone_number: string | null };
type Props = {
    numbers: Paginated<Row>;
    totals: Record<'connected' | 'pending' | 'disconnected' | 'green' | 'yellow' | 'red' | 'coexistence', number>;
    events: Event[];
    filters: { quality?: string; status?: string; type?: string; q?: string };
};

export default function NumbersIndex({ numbers, totals, events, filters }: Props) {
    const canManage = (usePage<SharedProps>().props.auth.admin?.abilities ?? []).includes('companies.manage');
    const [q, setQ] = useState(filters.q ?? '');
    const apply = (next: Record<string, string | undefined>) => router.get('/admin/numbers', { ...filters, ...next }, { preserveState: true, replace: true });

    return (
        <AdminLayout title="Numbers & health">
            <PageHeader title="Numbers & health" description="Every connected WhatsApp number and the quality signals Meta reports for it. Lowest quality is listed first." />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Kpi label="Connected" value={number(totals.connected)} hint={`${totals.pending} onboarding, ${totals.disconnected} disconnected`} />
                <Kpi label="High quality" value={number(totals.green)} hint="Rated GREEN by Meta" />
                <Kpi label="Needs attention" value={number(totals.yellow + totals.red)} hint={`${totals.red} rated low`} />
                <Kpi label="Coexistence numbers" value={number(totals.coexistence)} hint="Shared with the Business app, 20 msg/s" />
            </div>

            <div className="mt-4 mb-3 flex flex-wrap items-center gap-2">
                <Select className="w-auto" value={filters.quality ?? ''} onChange={(e) => apply({ quality: e.target.value || undefined })} aria-label="Quality">
                    <option value="">Any quality</option>
                    <option value="GREEN">High</option>
                    <option value="YELLOW">Medium</option>
                    <option value="RED">Low</option>
                </Select>
                <Select className="w-auto" value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value || undefined })} aria-label="Status">
                    <option value="">Any status</option>
                    <option value="connected">Connected</option>
                    <option value="pending">Onboarding</option>
                    <option value="disconnected">Disconnected</option>
                </Select>
                <Select className="w-auto" value={filters.type ?? ''} onChange={(e) => apply({ type: e.target.value || undefined })} aria-label="Onboarding">
                    <option value="">Any onboarding</option>
                    <option value="new_number">New number</option>
                    <option value="migrated">Migrated</option>
                    <option value="coexistence">Coexistence</option>
                </Select>
                <form onSubmit={(e: FormEvent) => { e.preventDefault(); apply({ q: q || undefined }); }} className="ml-auto w-full sm:w-64">
                    <Input type="search" placeholder="Number, name or company" value={q} onChange={(e) => setQ(e.target.value)} />
                </form>
            </div>

            <Card>
                <Table head={['Number', 'Company', 'Quality', 'Messaging limit', 'Onboarding', 'Status', 'Synced', '']} empty="No numbers match. Numbers appear here once a company connects WhatsApp.">
                    {numbers.data.map((n) => (
                        <tr key={n.id}>
                            <td>
                                <div className="font-mono text-[13px] font-semibold">{n.display_phone_number ?? '—'}</div>
                                <div className="text-[12.5px] text-muted">{n.verified_name ?? 'Name pending'}</div>
                            </td>
                            <td><Link href={`/admin/companies/${n.tenant_id}`} className="font-semibold hover:text-brand">{n.tenant}</Link></td>
                            <td><Pill tone={qualityTone(n.quality_rating)} dot>{qualityLabel(n.quality_rating)}</Pill></td>
                            <td className="tabular-nums">{tierLabel(n.messaging_limit_tier)}</td>
                            <td>
                                {humanize(n.onboarding_type)}
                                {n.onboarding_type === 'coexistence' && <div className="text-[12px] text-muted">{humanize(n.coexistence_status)}</div>}
                            </td>
                            <td>
                                <Pill tone={statusTone(n.status === 'connected' ? 'active' : n.status === 'pending' ? 'trialing' : 'closed')}>{humanize(n.status)}</Pill>
                                {n.ban_state && n.ban_state !== 'NONE' && <div className="mt-1"><Pill tone="bad">{humanize(n.ban_state)}</Pill></div>}
                            </td>
                            <td className="text-muted">{relative(n.last_synced_at)}</td>
                            <td className="text-right">
                                {canManage && n.status !== 'disconnected' && (
                                    <Button size="sm" variant="quiet" onClick={() => router.post(`/admin/numbers/${n.id}/refresh`, {}, { preserveScroll: true })}>Refresh</Button>
                                )}
                            </td>
                        </tr>
                    ))}
                </Table>
                <Pagination prev={numbers.prev_page_url} next={numbers.next_page_url} summary={numbers.total ? `${numbers.from}–${numbers.to} of ${numbers.total}` : null} />
            </Card>

            <Card title="Recent quality and account events" className="mt-4">
                <Table head={['Event', 'Company', 'Change', 'When']} empty="No events yet. Meta sends these when quality, limits or account status change.">
                    {events.map((e) => (
                        <tr key={e.id}>
                            <td className="font-medium">{humanize(e.event_type)}{e.display_phone_number && <div className="font-mono text-[12px] text-muted">{e.display_phone_number}</div>}</td>
                            <td><Link href={`/admin/companies/${e.tenant_id}`} className="hover:text-brand">{e.tenant}</Link></td>
                            <td className="text-muted">{e.old_value || e.new_value ? `${e.old_value ?? '—'} to ${e.new_value ?? '—'}` : '—'}</td>
                            <td className="text-muted">{dateTime(e.occurred_at)}</td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </AdminLayout>
    );
}
