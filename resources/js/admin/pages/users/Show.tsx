import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { Button, Card, Field, Input, Modal, PageHeader, Pill, Table, Textarea } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { date, dateTime, humanize, relative } from '../../lib/format';
import type { SharedProps } from '../../types';
import { DeleteModal, ResetTwoFactorModal, ReviewAccountModal, StatusModal } from './modals';

type User = {
    id: string; name: string; email: string; timezone: string | null; email_verified_at: string | null; disabled: boolean;
    two_factor: boolean; two_factor_confirmed_at: string | null; two_factor_required: boolean; review_account: boolean; review_account_note: string | null;
    sessions_revoked_at: string | null; last_login_at: string | null; created_at: string;
};
type Membership = { id: string; tenant_id: string; tenant: string | null; role: string | null; status: string; joined_at: string | null };
type Props = { user: User; memberships: Membership[]; audit: { id: string; action: string; actor_type: string; ip: string | null; created_at: string }[] };
type ModalName = null | 'details' | 'password' | 'signout' | 'status' | 'reset2fa' | 'review' | 'delete';

export default function UserShow({ user, memberships, audit }: Props) {
    const abilities = usePage<SharedProps>().props.auth.admin?.abilities ?? [];
    const canManage = abilities.includes('users.manage');
    const canDelete = abilities.includes('accounts.delete');
    const [modal, setModal] = useState<ModalName>(null);
    const close = () => setModal(null);
    const [busy, setBusy] = useState(false);
    const post = (path: string) => router.post(path, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <AdminLayout title={user.name}>
            <div className="mb-2 text-[13px]">
                <Link href="/admin/users" className="font-semibold text-muted hover:text-ink">Users</Link>
            </div>
            <PageHeader
                title={user.name}
                description={
                    <span className="inline-flex flex-wrap items-center gap-2">
                        {user.disabled ? <Pill tone="bad" dot>Disabled</Pill> : <Pill tone="good" dot>Active</Pill>}
                        {user.review_account && <Pill tone="warn">Review account</Pill>}
                        <span className="text-muted">{user.email}</span>
                    </span>
                }
                actions={canManage && <Button variant="primary" onClick={() => setModal('details')}>Edit details</Button>}
            />

            <div className="grid gap-4 lg:grid-cols-2">
                <Card title="Details" padded>
                    <Rows rows={[
                        ['Name', user.name],
                        ['Email', <span className="inline-flex flex-wrap items-center gap-2">{user.email}{user.email_verified_at ? <Pill tone="good">Verified</Pill> : <Pill tone="warn">Not verified</Pill>}</span>],
                        ['Time zone', user.timezone ?? '—'],
                        ['Joined', date(user.created_at)],
                        ['Last sign-in', user.last_login_at ? `${relative(user.last_login_at)} (${dateTime(user.last_login_at)})` : 'Never'],
                    ]} />
                    {canManage && !user.email_verified_at && (
                        <Button size="sm" className="mt-3" disabled={busy} onClick={() => post(`/admin/users/${user.id}/verify-email`)}>Mark email as verified</Button>
                    )}
                </Card>

                <Card title="Password and sessions" padded>
                    <p className="text-[13.5px] text-ink-2">
                        Send the normal reset email so they choose a password themselves, or set one for them (they are signed out everywhere and told by email).
                    </p>
                    {user.sessions_revoked_at && <p className="mt-2 text-[12.5px] text-muted">Last signed out by support {relative(user.sessions_revoked_at)}.</p>}
                    {canManage && (
                        <div className="mt-3 flex flex-wrap gap-2">
                            <Button disabled={busy} onClick={() => post(`/admin/users/${user.id}/password-reset`)}>Send reset link</Button>
                            <Button onClick={() => setModal('password')}>Set a new password</Button>
                            <Button onClick={() => setModal('signout')}>Sign out everywhere</Button>
                        </div>
                    )}
                </Card>

                <Card title="Two-factor sign-in" padded>
                    <Rows rows={[
                        ['Status', user.review_account ? 'Not asked (review account)' : user.two_factor ? `On since ${date(user.two_factor_confirmed_at)}` : 'Off'],
                        ['Required', user.two_factor_required ? 'Yes, workspace Owner' : 'No'],
                    ]} />
                    {user.review_account && user.review_account_note && <p className="mt-2 text-[12.5px] text-muted">Review account: {user.review_account_note}</p>}
                    {canManage && (
                        <div className="mt-3 flex flex-wrap gap-2">
                            {user.two_factor && <Button onClick={() => setModal('reset2fa')}>Reset two-factor</Button>}
                            <Button onClick={() => setModal('review')}>{user.review_account ? 'End review account' : 'Make review account'}</Button>
                        </div>
                    )}
                </Card>

                <Card title="Account status" padded>
                    <p className="text-[13.5px] text-ink-2">
                        {user.disabled
                            ? 'This user cannot sign in to any workspace.'
                            : user.review_account
                              ? 'Protected: a review account cannot be disabled, removed from its workspace or given another role. End the review account first.'
                              : 'Disabling signs them out of every workspace and revokes their API tokens.'}
                    </p>
                    {canManage && (user.disabled || !user.review_account) && (
                        <Button className="mt-3" variant={user.disabled ? 'primary' : 'danger'} onClick={() => setModal('status')}>{user.disabled ? 'Re-enable user' : 'Disable user'}</Button>
                    )}
                </Card>
            </div>

            {canDelete && (
                <Card title="Delete user" padded className="mt-4">
                    <p className="text-[13.5px] text-ink-2">
                        Permanently removes this person: their sign-in, workspace memberships, sessions and API tokens. Messages and notes they wrote stay in the workspaces.
                        {user.review_account ? ' The review account must be ended first.' : ''}
                    </p>
                    <Button className="mt-3" variant="danger" disabled={user.review_account} onClick={() => setModal('delete')}>Delete user</Button>
                </Card>
            )}

            <Card title="Workspaces" className="mt-4">
                <Table head={['Workspace', 'Role', 'Membership', 'Joined']} empty="Not a member of any workspace.">
                    {memberships.map((m) => (
                        <tr key={m.id}>
                            <td><Link href={`/admin/companies/${m.tenant_id}`} className="font-semibold hover:text-brand">{m.tenant ?? '—'}</Link></td>
                            <td>{m.role ?? '—'}</td>
                            <td>{humanize(m.status)}</td>
                            <td className="text-muted">{date(m.joined_at)}</td>
                        </tr>
                    ))}
                </Table>
            </Card>

            <Card title="Recent activity" className="mt-4">
                <Table head={['Action', 'By', 'IP address', 'When']} empty="No activity recorded yet.">
                    {audit.map((a) => (
                        <tr key={a.id}>
                            <td className="font-medium">{humanize(a.action)}</td>
                            <td className="text-muted">{humanize(a.actor_type)}</td>
                            <td className="text-muted">{a.ip ?? '—'}</td>
                            <td className="text-muted">{dateTime(a.created_at)}</td>
                        </tr>
                    ))}
                </Table>
            </Card>

            {modal === 'details' && <DetailsModal user={user} onClose={close} />}
            {modal === 'password' && <PasswordModal user={user} onClose={close} />}
            {modal === 'signout' && <SignOutModal user={user} onClose={close} />}
            {modal === 'status' && <StatusModal user={user} onClose={close} />}
            {modal === 'reset2fa' && <ResetTwoFactorModal user={user} onClose={close} />}
            {modal === 'review' && <ReviewAccountModal user={user} onClose={close} />}
            {modal === 'delete' && (
                <DeleteModal title={`Delete ${user.name}`} url={`/admin/users/${user.id}`} confirmLabel="Type their email to confirm" confirmValue={user.email} onClose={close}>
                    This cannot be undone. If they are the only Owner of a workspace with other members, make someone else Owner first (or delete the company).
                </DeleteModal>
            )}
        </AdminLayout>
    );
}

function Rows({ rows }: { rows: [string, ReactNode][] }) {
    return (
        <dl className="grid grid-cols-[9rem_1fr] gap-x-4 gap-y-2 text-[13.5px]">
            {rows.map(([k, v]) => (
                <div key={k} className="contents"><dt className="text-muted">{k}</dt><dd>{v}</dd></div>
            ))}
        </dl>
    );
}

function DetailsModal({ user, onClose }: { user: User; onClose: () => void }) {
    const form = useForm({ name: user.name, email: user.email, timezone: user.timezone ?? '', email_verified: user.email_verified_at !== null });
    const emailChanged = form.data.email.trim().toLowerCase() !== user.email.toLowerCase();
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(`/admin/users/${user.id}`, { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Modal open onClose={onClose} title={`Edit ${user.name}`}
            footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" form="user-details" disabled={form.processing}>Save</Button></>}>
            <form id="user-details" onSubmit={submit} className="space-y-3.5">
                <Field label="Name" error={form.errors.name}>
                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required maxLength={120} />
                </Field>
                <Field label="Sign-in email" error={form.errors.email} hint={emailChanged ? 'Both the old and the new address get an email about the change.' : undefined}>
                    <Input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required maxLength={190} />
                </Field>
                <label className="flex items-center gap-2 text-[13.5px]">
                    <input type="checkbox" checked={form.data.email_verified} onChange={(e) => form.setData('email_verified', e.target.checked)} />
                    {emailChanged ? 'The new address is confirmed (do not ask them to verify it)' : 'Email is verified'}
                </label>
                <Field label="Time zone" error={form.errors.timezone} hint="Optional, e.g. Asia/Dubai.">
                    <Input value={form.data.timezone} onChange={(e) => form.setData('timezone', e.target.value)} placeholder="Asia/Dubai" />
                </Field>
            </form>
        </Modal>
    );
}

/** A strong random password: letters in both cases, digits and a few symbols. */
const generate = (): string => {
    const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%*-_'];
    const all = sets.join('');
    const random = (n: number) => (crypto.getRandomValues(new Uint32Array(1))[0] ?? 0) % n;
    const pick = (chars: string) => chars.charAt(random(chars.length));
    const out = [...sets.map(pick), ...Array.from({ length: 12 }, () => pick(all))];
    for (let i = out.length - 1; i > 0; i--) {
        const j = random(i + 1);
        [out[i], out[j]] = [out[j] as string, out[i] as string];
    }
    return out.join('');
};

function PasswordModal({ user, onClose }: { user: User; onClose: () => void }) {
    const form = useForm({ password: '', reason: '' });
    const [show, setShow] = useState(false);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/admin/users/${user.id}/password`, { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Modal open onClose={onClose} title={`Set a new password for ${user.name}`}
            footer={<><Button onClick={onClose}>Cancel</Button><Button variant="danger" form="user-password" disabled={form.processing}>Set password</Button></>}>
            <form id="user-password" onSubmit={submit} className="space-y-3.5">
                <p className="text-[13.5px] text-ink-2">
                    Only after confirming who is asking. They are signed out on every device and get an email that support changed their password.
                    Share the password with them securely; it is not shown again.
                </p>
                <Field label="New password" error={form.errors.password} hint="At least 10 characters with upper and lower case letters and a number.">
                    <div className="flex gap-2">
                        <Input type={show ? 'text' : 'password'} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required autoComplete="new-password" className="font-mono" />
                        <Button type="button" size="sm" onClick={() => (form.setData('password', generate()), setShow(true))}>Generate</Button>
                        <Button type="button" size="sm" variant="quiet" onClick={() => setShow(!show)}>{show ? 'Hide' : 'Show'}</Button>
                        {form.data.password && <Button type="button" size="sm" variant="quiet" onClick={() => void navigator.clipboard?.writeText(form.data.password)}>Copy</Button>}
                    </div>
                </Field>
                <Field label="Reason" error={form.errors.reason} hint="Recorded in the audit log (the password never is).">
                    <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                </Field>
            </form>
        </Modal>
    );
}

function SignOutModal({ user, onClose }: { user: User; onClose: () => void }) {
    const form = useForm({ reason: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/admin/users/${user.id}/sign-out`, { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Modal open onClose={onClose} title={`Sign ${user.name} out everywhere`}
            footer={<><Button onClick={onClose}>Cancel</Button><Button variant="danger" form="user-signout" disabled={form.processing}>Sign out everywhere</Button></>}>
            <form id="user-signout" onSubmit={submit} className="space-y-3.5">
                <p className="text-[13.5px] text-ink-2">Every browser where they are signed in is signed out at its next request. Their password and API keys stay the same.</p>
                <Field label="Reason" error={form.errors.reason} hint="Recorded in the audit log.">
                    <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                </Field>
            </form>
        </Modal>
    );
}
