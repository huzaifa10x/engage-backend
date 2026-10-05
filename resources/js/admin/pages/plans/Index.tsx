import { Link, router, usePage } from '@inertiajs/react';
import { Button, Card, PageHeader, Pill, Table } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { money, number } from '../../lib/format';
import type { SharedProps } from '../../types';

type PlanRow = {
    id: string;
    key: string;
    name: string;
    description: string | null;
    is_public: boolean;
    is_active: boolean;
    version: number | null;
    price_monthly_minor: number | null;
    price_yearly_minor: number | null;
    subscribers: number;
};

const price = (minor: number | null) => (minor === null ? 'Custom' : minor === 0 ? 'Free' : money(minor));

export default function PlansIndex({ plans }: { plans: PlanRow[] }) {
    const canManage = (usePage<SharedProps>().props.auth.admin?.abilities ?? []).includes('billing.manage');

    const toggle = (plan: PlanRow) => {
        const message = plan.is_active
            ? `Stop offering “${plan.name}”? Nobody can buy it any more. The ${plan.subscribers} current subscriber(s) keep it.`
            : `Offer “${plan.name}” for sale again?`;
        if (window.confirm(message)) router.patch(`/admin/plans/${plan.id}/active`, { active: !plan.is_active }, { preserveScroll: true });
    };

    return (
        <AdminLayout title="Plans">
            <PageHeader
                title="Plans"
                description="Pricing, limits, rate limits and feature access for every plan. Saving a plan publishes a new version, so there is always a record of what each customer bought."
                actions={canManage && <Link href="/admin/plans/create" className="inline-flex h-9 items-center rounded-lg bg-brand px-3.5 text-[13.5px] font-semibold text-white hover:bg-brand-600">New plan</Link>}
            />
            <Card>
                <Table head={['Plan', 'Monthly', 'Yearly', 'Subscribers', 'Status', '']} empty="No plans yet.">
                    {plans.map((p) => (
                        <tr key={p.id}>
                            <td>
                                <div className="font-semibold">{p.name} <span className="ml-1 font-mono text-[12px] font-normal text-muted">{p.key} · v{p.version ?? '—'}</span></div>
                                <div className="max-w-md truncate text-[12.5px] text-muted">{p.description}</div>
                            </td>
                            <td className="tabular-nums">{price(p.price_monthly_minor)}</td>
                            <td className="tabular-nums">{price(p.price_yearly_minor)}</td>
                            <td className="tabular-nums">{number(p.subscribers)}</td>
                            <td>
                                <div className="flex flex-wrap gap-1.5">
                                    <Pill tone={p.is_active ? 'good' : 'grey'} dot>{p.is_active ? 'Active' : 'Inactive'}</Pill>
                                    {!p.is_public && <Pill tone="info">Hidden</Pill>}
                                </div>
                            </td>
                            <td>
                                {canManage && (
                                    <div className="flex justify-end gap-2">
                                        <Link href={`/admin/plans/${p.id}`} className="inline-flex h-8 items-center rounded-lg border border-line bg-white px-3 text-[13px] font-semibold text-ink-2 hover:bg-line-2">Edit</Link>
                                        <Button size="sm" variant="quiet" onClick={() => toggle(p)}>{p.is_active ? 'Deactivate' : 'Activate'}</Button>
                                    </div>
                                )}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </AdminLayout>
    );
}
