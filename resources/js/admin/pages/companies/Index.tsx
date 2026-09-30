import { Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Card, Initials, Input, PageHeader, Pagination, Pill, Select, Table } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { date, humanize, statusTone } from '../../lib/format';
import type { Paginated, PlanOption } from '../../types';

type Company = { id: string; name: string; slug: string; status: string; plan: string; subscription_status: string | null; members: number; created_at: string };
type Props = { companies: Paginated<Company>; filters: { q?: string; status?: string; plan?: string }; plans: PlanOption[] };

const STATUSES = [
    ['', 'All'],
    ['active', 'Active'],
    ['trialing', 'On trial'],
    ['suspended', 'Suspended'],
    ['closed', 'Closed'],
] as const;

export default function CompaniesIndex({ companies, filters, plans }: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const apply = (next: Record<string, string | undefined>) =>
        router.get('/admin/companies', { ...filters, ...next }, { preserveState: true, replace: true });

    const search = (e: FormEvent) => {
        e.preventDefault();
        apply({ q: q || undefined });
    };

    return (
        <AdminLayout title="Companies">
            <PageHeader title="Companies" description={`${companies.total} businesses on 10X Engage. Open one to manage its plan, members and access.`} />

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <div className="flex flex-wrap gap-1.5" role="tablist" aria-label="Status">
                    {STATUSES.map(([value, label]) => {
                        const on = (filters.status ?? '') === value;
                        return (
                            <button
                                key={value}
                                role="tab"
                                aria-selected={on}
                                onClick={() => apply({ status: value || undefined })}
                                className={`rounded-full border px-3 py-1 text-[13px] font-semibold ${on ? 'border-brand bg-brand-50 text-brand-600' : 'border-line bg-white text-ink-2 hover:bg-line-2'}`}
                            >
                                {label}
                            </button>
                        );
                    })}
                </div>
                <Select className="ml-auto w-auto" value={filters.plan ?? ''} onChange={(e) => apply({ plan: e.target.value || undefined })} aria-label="Plan">
                    <option value="">All plans</option>
                    {[...new Map(plans.map((p) => [p.plan_key, p.label.split(' (')[0]])).entries()].map(([key, name]) => (
                        <option key={key} value={key}>{name}</option>
                    ))}
                </Select>
                <form onSubmit={search} className="w-full sm:w-64">
                    <Input type="search" placeholder="Search name, slug or billing email" value={q} onChange={(e) => setQ(e.target.value)} />
                </form>
            </div>

            <Card>
                <Table head={['Company', 'Plan', 'Members', 'Status', 'Joined']} empty="No companies match these filters.">
                    {companies.data.map((c) => (
                        <tr key={c.id} className="cursor-pointer hover:bg-canvas" onClick={() => router.visit(`/admin/companies/${c.id}`)}>
                            <td>
                                <div className="flex items-center gap-2.5">
                                    <Initials name={c.name} />
                                    <div>
                                        <Link href={`/admin/companies/${c.id}`} className="font-semibold" onClick={(e) => e.stopPropagation()}>
                                            {c.name}
                                        </Link>
                                        <div className="text-[12.5px] text-muted">{c.slug}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span className="font-medium">{c.plan}</span>
                                {c.subscription_status === 'trialing' && <span className="ml-1.5 text-[12.5px] text-info">trial</span>}
                                {c.subscription_status === 'past_due' && <span className="ml-1.5 text-[12.5px] text-warn">past due</span>}
                            </td>
                            <td className="tabular-nums">{c.members}</td>
                            <td><Pill tone={statusTone(c.status)} dot>{humanize(c.status)}</Pill></td>
                            <td className="text-muted">{date(c.created_at)}</td>
                        </tr>
                    ))}
                </Table>
                <Pagination prev={companies.prev_page_url} next={companies.next_page_url} summary={companies.total ? `${companies.from}–${companies.to} of ${companies.total}` : null} />
            </Card>
        </AdminLayout>
    );
}
