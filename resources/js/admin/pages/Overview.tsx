import { Link } from '@inertiajs/react';
import { Card, Kpi, PageHeader, Pill, Table } from '../components/ui';
import AdminLayout from '../layouts/AdminLayout';
import { date, money, number, relative, statusTone } from '../lib/format';
import type { Kpis, PlanMixRow } from '../types';

type Signup = { id: string; name: string; status: string; created_at: string; plan: string | null; subscription_status: string | null };
type TrialRow = { id: string; name: string; plan: string; trial_ends_at: string };
type Criterion = { criterion: string; target: string; current: string | null; status: 'manual' | 'not_measurable' | 'met' | 'unmet' };

type Props = { kpis: Kpis; planMix: PlanMixRow[]; latestSignups: Signup[]; trialsEnding: TrialRow[]; partnerEligibility: Criterion[] };

export default function Overview({ kpis, planMix, latestSignups, trialsEnding, partnerEligibility }: Props) {
    const totalCompanies = planMix.reduce((s, p) => s + p.companies, 0) || 1;

    return (
        <AdminLayout title="Overview">
            <PageHeader title="Platform overview" description="Every company on 10X Engage, at a glance." />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Kpi label="Monthly recurring revenue" value={money(kpis.mrr_minor)} hint={`${kpis.paying} paying companies`} />
                <Kpi label="Companies" value={number(kpis.companies)} hint={`${kpis.new_30d} new in 30 days`} />
                <Kpi label="On trial" value={number(kpis.trialing)} hint="14-day Pro trial" />
                <Kpi label="Suspended" value={number(kpis.suspended)} hint={kpis.past_due ? `${kpis.past_due} past due` : 'None past due'} />
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_380px]">
                <Card title="Plan mix" aside={<Link href="/admin/subscriptions" className="text-[13px] font-semibold text-brand">Subscriptions</Link>}>
                    <div className="space-y-3 p-4">
                        {planMix.map((p) => (
                            <div key={p.key}>
                                <div className="mb-1 flex justify-between text-[13.5px]">
                                    <span className="font-semibold">
                                        {p.name}
                                        {p.trialing > 0 && <span className="ml-2 font-normal text-muted">{p.trialing} on trial</span>}
                                    </span>
                                    <span className="tabular-nums text-muted">
                                        {p.companies} · {money(p.mrr_minor)}
                                    </span>
                                </div>
                                <div className="h-2 overflow-hidden rounded-full bg-line-2">
                                    <div className="h-full rounded-full bg-brand" style={{ width: `${(p.companies / totalCompanies) * 100}%` }} />
                                </div>
                            </div>
                        ))}
                        {planMix.length === 0 && <p className="py-6 text-center text-muted">No companies yet. The first signup appears here.</p>}
                    </div>
                </Card>

                <Card title="Tech Partner eligibility">
                    <ul className="divide-y divide-line-2">
                        {partnerEligibility.map((c) => (
                            <li key={c.criterion} className="px-4 py-3">
                                <div className="flex items-start justify-between gap-3">
                                    <span className="text-[13.5px] font-medium">{c.criterion}</span>
                                    <Pill tone={c.status === 'met' ? 'good' : c.status === 'unmet' ? 'warn' : 'grey'}>
                                        {c.status === 'manual' ? 'Check manually' : c.status === 'not_measurable' ? 'Needs messaging data' : c.status === 'met' ? 'Met' : 'Not yet'}
                                    </Pill>
                                </div>
                                <div className="mt-0.5 text-[12.5px] text-muted">
                                    Target {c.target}
                                    {c.current && `, now ${c.current}`}
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <Card title="Latest signups">
                    <Table head={['Company', 'Plan', 'Joined']} empty="No signups yet.">
                        {latestSignups.map((s) => (
                            <tr key={s.id}>
                                <td>
                                    <Link href={`/admin/companies/${s.id}`} className="font-semibold hover:text-brand">{s.name}</Link>
                                </td>
                                <td>
                                    <Pill tone={statusTone(s.subscription_status ?? s.status)}>{s.plan ?? 'Free'}{s.subscription_status === 'trialing' ? ' trial' : ''}</Pill>
                                </td>
                                <td className="text-muted">{relative(s.created_at)}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
                <Card title="Trials ending this week">
                    <Table head={['Company', 'Plan', 'Ends']} empty="No trials end in the next 7 days.">
                        {trialsEnding.map((t) => (
                            <tr key={t.id}>
                                <td>
                                    <Link href={`/admin/companies/${t.id}`} className="font-semibold hover:text-brand">{t.name}</Link>
                                </td>
                                <td className="text-muted">{t.plan}</td>
                                <td className="tabular-nums">{date(t.trial_ends_at)}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </div>
        </AdminLayout>
    );
}
