import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { Button, Field, Input, Modal, Textarea } from '../../components/ui';

/** What these actions need to know about the user. */
export type UserRef = { id: string; name: string; disabled: boolean; review_account: boolean };

export function StatusModal({ user, onClose }: { user: UserRef; onClose: () => void }) {
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

/** Lost phone and recovery codes: the user signs in with password + emailed code and sets up a new authenticator. */
export function ResetTwoFactorModal({ user, onClose }: { user: UserRef; onClose: () => void }) {
    const form = useForm({ reason: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/admin/users/${user.id}/reset-two-factor`, { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Modal open onClose={onClose} title={`Reset two-factor sign-in for ${user.name}`}
            footer={<><Button onClick={onClose}>Cancel</Button><Button variant="danger" form="user-2fa-reset" disabled={form.processing}>Reset two-factor</Button></>}>
            <form id="user-2fa-reset" onSubmit={submit} className="space-y-3.5">
                <p className="text-[13.5px] text-ink-2">
                    Only do this after confirming who is asking (for example a call from the number on file). Their authenticator and recovery codes stop working;
                    they sign in with their password and an emailed code, and workspace Owners are asked to set up a new authenticator straight away. They get an email about it.
                </p>
                <Field label="Reason" error={form.errors.reason} hint="Recorded in the audit log.">
                    <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                </Field>
            </form>
        </Modal>
    );
}

/** The account given to Meta App Review: email + password only, never an authenticator or emailed code. */
export function ReviewAccountModal({ user, onClose }: { user: UserRef; onClose: () => void }) {
    const on = !user.review_account;
    const form = useForm({ enabled: on, reason: on ? 'Meta App Review test account' : '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(`/admin/users/${user.id}/review-account`, { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Modal open onClose={onClose} title={on ? `Make ${user.name} a review account` : `End review account for ${user.name}`}
            footer={<><Button onClick={onClose}>Cancel</Button><Button variant={on ? 'danger' : 'primary'} form="user-review" disabled={form.processing}>{on ? 'Make review account' : 'End review account'}</Button></>}>
            <form id="user-review" onSubmit={submit} className="space-y-3.5">
                {on ? (
                    <p className="text-[13.5px] text-ink-2">
                        This account will sign in with <b>email and password only</b>: no authenticator app and no emailed code, also as a workspace Owner.
                        Use it only for the account you give to Meta App Review, in its own workspace, with a long unique password. Any authenticator on it is removed.
                        Every sign-in is recorded in the audit log.
                    </p>
                ) : (
                    <p className="text-[13.5px] text-ink-2">The usual checks come back: an emailed code at sign-in, and Owners set up an authenticator on their next visit.</p>
                )}
                <Field label="Reason" error={form.errors.reason} hint="Recorded in the audit log and shown on this page.">
                    <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                </Field>
            </form>
        </Modal>
    );
}

/** Permanent deletion: type the name (or email) to confirm, and give a reason. */
export function DeleteModal({ title, url, confirmLabel, confirmValue, children, onClose }: {
    title: string; url: string; confirmLabel: string; confirmValue: string; children: ReactNode; onClose: () => void;
}) {
    const form = useForm({ confirm: '', reason: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.delete(url, { preserveScroll: true });
    };
    const matches = form.data.confirm.trim().toLowerCase() === confirmValue.trim().toLowerCase();
    return (
        <Modal open onClose={onClose} title={title}
            footer={<><Button onClick={onClose}>Cancel</Button><Button variant="danger" form="delete-form" disabled={form.processing || !matches}>Delete permanently</Button></>}>
            <form id="delete-form" onSubmit={submit} className="space-y-3.5">
                <div className="text-[13.5px] text-ink-2">{children}</div>
                <Field label={confirmLabel} error={form.errors.confirm}>
                    <Input value={form.data.confirm} onChange={(e) => form.setData('confirm', e.target.value)} placeholder={confirmValue} autoComplete="off" />
                </Field>
                <Field label="Reason" error={form.errors.reason} hint="Kept in the platform audit log after the deletion.">
                    <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                </Field>
            </form>
        </Modal>
    );
}
