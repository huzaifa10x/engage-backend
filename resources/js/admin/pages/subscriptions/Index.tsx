import { Link } from '@inertiajs/react';
import { Card, Kpi, PageHeader, Table } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { date, dateTime, humanize, money, number, relative } from '../../lib/format';
import type { Kpis, PlanMixRow } from '../../types';

type Props = {
    kpis: Kpis;
    planMix: PlanMixRow[];
    trialsEnding: { id: string; name: string; plan: string; trial_ends_at: string }[];
    events: { id: string; type: string; from_status: string | null; to_status: string; occurred_at: string; tenant_id: string; tenant: string }[];
};

export default function SubscriptionsIndex({ kpis, planMix, trialsEnding, events }: Props) {
    const mrr = planMix.reduce((s, p) => s + p.mrr_minor, 0) || 1;

    return (
        <AdminLayout title="Subscriptions">
            <PageHeader title="Subscriptions" description="Plans, trials and renewals across every company. Clients pay Meta directly for messages, so this is software revenue only." />
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Kpi label="MRR" value={money(kpis.mrr_minor)} hint={`ARR ${money(kpis.mrr_minor * 12)}`} />
                <Kpi label="Paying companies" value={number(kpis.paying)} />
                <Kpi label="On trial" value={number(kpis.trialing)} />
                <Kpi label="Past due" value={number(kpis.past_due)} hint="Dunning starts with Stripe billing" />
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <Card title="Plan distribution">
                    <Table head={['Plan', 'Companies', 'MRR', 'Share of MRR']} empty="No subscriptions yet.">
                        {planMix.map((p) => (
                            <tr key={p.key}>
                                <td className="font-semibold">{p.name}</td>
                                <td className="tabular-nums">{p.companies}{p.trialing > 0 && <span className="ml-1.5 text-[12.5px] text-muted">({p.trialing} trial)</span>}</td>
                                <td className="tabular-nums">{money(p.mrr_minor)}</td>
                                <td className="tabular-nums text-muted">{Math.round((p.mrr_minor / mrr) * 100)}%</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
                <Card title="Trials ending in the next 14 days">
                    <Table head={['Company', 'Plan', 'Ends']} empty="No trials end soon.">
                        {trialsEnding.map((t) => (
                            <tr key={t.id}>
                                <td><Link href={`/admin/companies/${t.id}`} className="font-semibold hover:text-brand">{t.name}</Link></td>
                                <td className="text-muted">{t.plan}</td>
                                <td>{date(t.trial_ends_at)} <span className="text-[12.5px] text-muted">{relative(t.trial_ends_at)}</span></td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </div>

            <Card title="Recent subscription changes" className="mt-4">
                <Table head={['Company', 'Change', 'When']} empty="No changes yet.">
                    {events.map((e) => (
                        <tr key={e.id}>
                            <td><Link href={`/admin/companies/${e.tenant_id}`} className="font-semibold hover:text-brand">{e.tenant}</Link></td>
                            <td>{humanize(e.type)}{e.from_status ? <span className="text-muted"> from {humanize(e.from_status)}</span> : null}<span className="text-muted"> to {humanize(e.to_status)}</span></td>
                            <td className="text-muted">{dateTime(e.occurred_at)}</td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </AdminLayout>
    );
}
