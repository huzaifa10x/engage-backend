import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, Card, Field, Input, Modal, PageHeader, Pill, Select, Table } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { humanize, relative } from '../../lib/format';
import type { SharedProps } from '../../types';

type AdminRow = { id: string; name: string; email: string; role: string; role_label: string; active: boolean; two_factor: boolean; last_login_at: string | null };
type Role = { value: string; label: string; abilities: string[] };

export default function TeamIndex({ admins, roles }: { admins: AdminRow[]; roles: Role[] }) {
    const me = usePage<SharedProps>().props.auth.admin;
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<AdminRow | null>(null);

    return (
        <AdminLayout title="Platform team">
            <PageHeader title="Platform team" description="10X staff with access to this admin. Two-factor is required for everyone." actions={<Button variant="primary" onClick={() => setAdding(true)}>Add team member</Button>} />

            <Card>
                <Table head={['Member', 'Role', 'Two-factor', 'Last sign-in', 'Status', '']}>
                    {admins.map((a) => (
                        <tr key={a.id}>
                            <td><div className="font-semibold">{a.name}{a.id === me?.id && <span className="ml-1.5 text-[12.5px] font-normal text-muted">(you)</span>}</div><div className="text-[12.5px] text-muted">{a.email}</div></td>
                            <td><Pill tone="brand">{a.role_label}</Pill></td>
                            <td>{a.two_factor ? <Pill tone="good">On</Pill> : <Pill tone="warn">Enrols at next sign-in</Pill>}</td>
                            <td className="text-muted">{relative(a.last_login_at)}</td>
                            <td>{a.active ? <Pill tone="good" dot>Active</Pill> : <Pill tone="grey" dot>Disabled</Pill>}</td>
                            <td className="text-right"><Button size="sm" variant="quiet" onClick={() => setEditing(a)}>Manage</Button></td>
                        </tr>
                    ))}
                </Table>
            </Card>

            <Card title="What each role can do" className="mt-4">
                <Table head={['Role', 'Access']}>
                    {roles.map((r) => (
                        <tr key={r.value}>
                            <td className="font-semibold">{r.label}</td>
                            <td className="text-muted">{r.abilities.map(humanize).join(', ')}</td>
                        </tr>
                    ))}
                </Table>
            </Card>

            {adding && <AddModal roles={roles} onClose={() => setAdding(false)} />}
            {editing && <EditModal admin={editing} roles={roles} onClose={() => setEditing(null)} />}
        </AdminLayout>
    );
}

function AddModal({ roles, onClose }: { roles: Role[]; onClose: () => void }) {
    const form = useForm({ name: '', email: '', role: 'support', password: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/admin/team', { onSuccess: onClose });
    };
    return (
        <Modal open onClose={onClose} title="Add team member" footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" form="add-admin" disabled={form.processing}>Add team member</Button></>}>
            <form id="add-admin" onSubmit={submit} className="space-y-3.5">
                <Field label="Name" error={form.errors.name}><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></Field>
                <Field label="Work email" error={form.errors.email}><Input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required /></Field>
                <Field label="Role" error={form.errors.role}>
                    <Select value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                        {roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                    </Select>
                </Field>
                <Field label="Temporary password" error={form.errors.password} hint="Share it securely. They enrol two-factor at first sign-in.">
                    <Input type="password" autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required />
                </Field>
            </form>
        </Modal>
    );
}

function EditModal({ admin, roles, onClose }: { admin: AdminRow; roles: Role[]; onClose: () => void }) {
    const form = useForm({ role: admin.role, active: admin.active });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(`/admin/team/${admin.id}`, { onSuccess: onClose });
    };
    const resetTwoFactor = () => {
        if (confirm(`Reset two-factor for ${admin.name}? They will enrol a new authenticator at next sign-in.`)) {
            router.post(`/admin/team/${admin.id}/reset-two-factor`, {}, { onSuccess: onClose });
        }
    };
    return (
        <Modal open onClose={onClose} title={`Manage ${admin.name}`}
            footer={<>{admin.two_factor && <Button className="mr-auto" variant="quiet" onClick={resetTwoFactor}>Reset two-factor</Button>}<Button onClick={onClose}>Cancel</Button><Button variant="primary" form="edit-admin" disabled={form.processing}>Save changes</Button></>}>
            <form id="edit-admin" onSubmit={submit} className="space-y-3.5">
                <Field label="Role" error={form.errors.role}>
                    <Select value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                        {roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                    </Select>
                </Field>
                <label className="flex items-center gap-2 text-[13.5px] font-medium">
                    <input type="checkbox" checked={form.data.active} onChange={(e) => form.setData('active', e.target.checked)} />
                    Can sign in
                </label>
            </form>
        </Modal>
    );
}
