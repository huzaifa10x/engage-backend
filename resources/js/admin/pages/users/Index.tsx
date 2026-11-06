import { Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Card, Input, Kpi, PageHeader, Pagination, Pill, Table } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { number, relative } from '../../lib/format';
import type { Paginated } from '../../types';

type User = {
    id: string; name: string; email: string; disabled: boolean; two_factor: boolean; review_account: boolean; review_account_note: string | null; last_login_at: string | null; created_at: string;
    memberships: { tenant_id: string; tenant: string | null; role: string | null }[];
};
type Props = { users: Paginated<User>; filters: { q?: string }; totals: { users: number; active_7d: number; disabled: number } };

export default function UsersIndex({ users, filters, totals }: Props) {
    const [q, setQ] = useState(filters.q ?? '');

    const search = (e: FormEvent) => {
        e.preventDefault();
        router.get('/admin/users', q ? { q } : {}, { preserveState: true, replace: true });
    };

    return (
        <AdminLayout title="Users">
            <PageHeader title="Users" description="Everyone who can sign in to a client workspace. Open a user to change their details, password or sign-in." />
            <div className="grid grid-cols-3 gap-3">
                <Kpi label="Total users" value={number(totals.users)} />
                <Kpi label="Signed in this week" value={number(totals.active_7d)} />
                <Kpi label="Disabled" value={number(totals.disabled)} />
            </div>

            <Card className="mt-4" title="All users" aside={
                <form onSubmit={search} className="w-64"><Input type="search" placeholder="Search name or email" value={q} onChange={(e) => setQ(e.target.value)} /></form>
            }>
                <Table head={['User', 'Workspaces', 'Last sign-in', 'Two-factor', 'Status', '']} empty="No users match this search.">
                    {users.data.map((u) => (
                        <tr key={u.id}>
                            <td>
                                <Link href={`/admin/users/${u.id}`} className="flex items-center gap-2 font-semibold hover:text-brand">
                                    {u.name}{u.review_account && <Pill tone="warn">Review account</Pill>}
                                </Link>
                                <div className="text-[12.5px] text-muted">{u.email}</div>
                            </td>
                            <td>
                                <div className="flex flex-wrap gap-1.5">
                                    {u.memberships.map((m) => (
                                        <Link key={m.tenant_id} href={`/admin/companies/${m.tenant_id}`} className="rounded-md bg-grey-bg px-2 py-0.5 text-[12.5px] hover:bg-brand-50">
                                            {m.tenant} <span className="text-muted">{m.role}</span>
                                        </Link>
                                    ))}
                                    {u.memberships.length === 0 && <span className="text-faint">None</span>}
                                </div>
                            </td>
                            <td className="text-muted">{relative(u.last_login_at)}</td>
                            <td>{u.review_account ? <span className="text-faint">Not asked</span> : u.two_factor ? <Pill tone="good">On</Pill> : <span className="text-faint">Off</span>}</td>
                            <td>{u.disabled ? <Pill tone="bad">Disabled</Pill> : <Pill tone="good">Active</Pill>}</td>
                            <td className="text-right">
                                <Link href={`/admin/users/${u.id}`} className="text-[13px] font-semibold text-brand hover:underline">Manage</Link>
                            </td>
                        </tr>
                    ))}
                </Table>
                <Pagination prev={users.prev_page_url} next={users.next_page_url} summary={users.total ? `${users.from}–${users.to} of ${users.total}` : null} />
            </Card>
        </AdminLayout>
    );
}
