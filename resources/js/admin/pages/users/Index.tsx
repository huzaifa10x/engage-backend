import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, Card, Field, Input, Kpi, Modal, PageHeader, Pagination, Pill, Table, Textarea } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { number, relative } from '../../lib/format';
import type { Paginated, SharedProps } from '../../types';

type User = {
    id: string; name: string; email: string; disabled: boolean; last_login_at: string | null; created_at: string;
    memberships: { tenant_id: string; tenant: string | null; role: string | null }[];
};
type Props = { users: Paginated<User>; filters: { q?: string }; totals: { users: number; active_7d: number; disabled: number } };

export default function UsersIndex({ users, filters, totals }: Props) {
    const canManage = (usePage<SharedProps>().props.auth.admin?.abilities ?? []).includes('users.manage');
    const [q, setQ] = useState(filters.q ?? '');
    const [target, setTarget] = useState<User | null>(null);

    const search = (e: FormEvent) => {
        e.preventDefault();
        router.get('/admin/users', q ? { q } : {}, { preserveState: true, replace: true });
    };

    return (
        <AdminLayout title="Users">
            <PageHeader title="Users" description="Everyone who can sign in to a client workspace." />
            <div className="grid grid-cols-3 gap-3">
                <Kpi label="Total users" value={number(totals.users)} />
                <Kpi label="Signed in this week" value={number(totals.active_7d)} />
                <Kpi label="Disabled" value={number(totals.disabled)} />
            </div>

            <Card className="mt-4" title="All users" aside={
                <form onSubmit={search} className="w-64"><Input type="search" placeholder="Search name or email" value={q} onChange={(e) => setQ(e.target.value)} /></form>
            }>
                <Table head={['User', 'Workspaces', 'Last sign-in', 'Status', '']} empty="No users match this search.">
                    {users.data.map((u) => (
                        <tr key={u.id}>
                            <td><div className="font-semibold">{u.name}</div><div className="text-[12.5px] text-muted">{u.email}</div></td>
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
                            <td>{u.disabled ? <Pill tone="bad">Disabled</Pill> : <Pill tone="good">Active</Pill>}</td>
                            <td className="text-right">
                                {canManage && <Button size="sm" variant="quiet" onClick={() => setTarget(u)}>{u.disabled ? 'Re-enable' : 'Disable'}</Button>}
                            </td>
                        </tr>
                    ))}
                </Table>
                <Pagination prev={users.prev_page_url} next={users.next_page_url} summary={users.total ? `${users.from}–${users.to} of ${users.total}` : null} />
            </Card>

            {target && <StatusModal user={target} onClose={() => setTarget(null)} />}
        </AdminLayout>
    );
}

function StatusModal({ user, onClose }: { user: User; onClose: () => void }) {
    const form = useForm({ active: user.disabled, reason: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(`/admin/users/${user.id}/status`, { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Modal open onClose={onClose} title={user.disabled ? `Re-enable ${user.name}` : `Disable ${user.name}`}
            footer={<><Button onClick={onClose}>Cancel</Button><Button variant={user.disabled ? 'primary' : 'danger'} form="user-status" disabled={form.processing}>{user.disabled ? 'Re-enable' : 'Disable'}</Button></>}>
            <form id="user-status" onSubmit={submit} className="space-y-3.5">
                <p className="text-[13.5px] text-ink-2">
                    {user.disabled ? 'They can sign in again straight away.' : 'They are signed out of every workspace and their API tokens are revoked.'}
                </p>
                <Field label="Reason" error={form.errors.reason} hint="Recorded in the audit log.">
                    <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                </Field>
            </form>
        </Modal>
    );
}
