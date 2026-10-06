import { router, useForm } from '@inertiajs/react';
import { QRCodeSVG } from 'qrcode.react';
import { useState, type FormEvent } from 'react';
import { Button, Card, Field, Input, Modal, PageHeader, Pill, Table } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { dateTime, relative } from '../../lib/format';
import type { Tone } from '../../types';

type TwoFactor = { enabled: boolean; method: 'app' | 'email' | null; required: boolean; recovery_codes_left: number; email: string };

type Session = {
    id: string;
    current: boolean;
    mine: boolean;
    admin: string | null;
    admin_email: string | null;
    ip: string | null;
    country: string | null;
    browser: string | null;
    os: string | null;
    device: string | null;
    last_active_at: string;
    signed_in_at: string | null;
};

type HistoryRow = {
    id: string;
    event: string;
    method: string | null;
    admin: string | null;
    email: string | null;
    ip: string | null;
    country: string | null;
    browser: string | null;
    os: string | null;
    device: string | null;
    at: string | null;
};

type Props = {
    twoFactor: TwoFactor;
    appSetup: { secret: string; otpauth_uri: string } | null;
    emailPending: boolean;
    seesEveryone: boolean;
    sessions: Session[];
    history: HistoryRow[];
    mail: { mailer: string; from: string };
};

type Action = { title: string; url: string; method: 'post' | 'delete'; confirm: string; danger?: boolean; note: string };

const EVENT: Record<string, [string, Tone]> = {
    login: ['Signed in', 'good'],
    logout: ['Signed out', 'grey'],
    failed_password: ['Wrong password', 'bad'],
    failed_two_factor: ['Wrong 2FA code', 'bad'],
    session_revoked: ['Signed out remotely', 'warn'],
};

const METHOD: Record<string, string> = { app: 'Authenticator app', email: 'Email code', recovery: 'Recovery code', none: 'Password only' };

const where = (r: { ip: string | null; country: string | null }) => [r.ip, r.country].filter(Boolean).join(' · ') || '—';
const device = (r: { browser: string | null; os: string | null; device: string | null }) => [r.browser, r.os, r.device].filter(Boolean).join(' · ') || 'Unknown device';

/** Every change to 2FA asks for the password again. */
function PasswordModal({ action, onClose }: { action: Action | null; onClose: () => void }) {
    const form = useForm({ password: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!action) return;
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); }, onError: () => form.reset('password') };
        if (action.method === 'delete') form.delete(action.url, options);
        else form.post(action.url, options);
    };

    return (
        <Modal
            open={action !== null}
            onClose={() => { form.reset(); form.clearErrors(); onClose(); }}
            title={action?.title ?? ''}
            footer={<><Button onClick={onClose}>Cancel</Button><Button variant={action?.danger ? 'danger' : 'primary'} form="confirm-password" disabled={form.processing || form.data.password === ''}>{action?.confirm}</Button></>}
        >
            <form id="confirm-password" onSubmit={submit} className="grid gap-4">
                <p className="text-[13.5px] text-muted">{action?.note}</p>
                <Field label="Your password" error={form.errors.password}>
                    <Input type="password" autoFocus autoComplete="current-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </Field>
            </form>
        </Modal>
    );
}

function CodeForm({ url, label, hint, submit }: { url: string; label: string; hint?: string; submit: string }) {
    const form = useForm({ code: '' });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(url, { preserveScroll: true, onError: () => form.reset() });
            }}
            className="grid max-w-xs gap-3"
        >
            <Field label={label} error={form.errors.code} hint={hint}>
                <Input inputMode="numeric" autoComplete="one-time-code" maxLength={6} className="text-center font-mono text-[18px] tracking-[0.35em]" value={form.data.code} onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, ''))} />
            </Field>
            <div className="flex gap-2">
                <Button variant="primary" disabled={form.processing || form.data.code.length !== 6}>{submit}</Button>
                <Button type="button" onClick={() => router.post('/admin/security/two-factor/cancel', {}, { preserveScroll: true })}>Cancel</Button>
            </div>
        </form>
    );
}

export default function SecurityIndex({ twoFactor: tf, appSetup, emailPending, seesEveryone, sessions, history, mail }: Props) {
    const [action, setAction] = useState<Action | null>(null);
    const [testing, setTesting] = useState(false);
    const others = sessions.filter((s) => s.mine && !s.current).length;

    const toApp: Action = { title: tf.enabled ? 'Switch to an authenticator app' : 'Turn on two-factor (authenticator app)', url: '/admin/security/two-factor/app', method: 'post', confirm: 'Continue', note: 'Next you will scan a QR code with Google Authenticator, 1Password or Authy. Nothing changes until you enter a code from the app.' };
    const toEmail: Action = { title: tf.enabled ? 'Switch to email codes' : 'Turn on two-factor (email codes)', url: '/admin/security/two-factor/email', method: 'post', confirm: 'Send me a code', note: `We will email a 6-digit code to ${tf.email}. Nothing changes until you enter it, so a mail problem cannot lock you out.` };
    const off: Action = { title: 'Turn off two-factor authentication', url: '/admin/security/two-factor', method: 'delete', confirm: 'Turn off 2FA', danger: true, note: 'Your account will be protected by your password only. This admin panel controls every customer workspace, so this is not recommended.' };
    const codes: Action = { title: 'New recovery codes', url: '/admin/security/recovery-codes', method: 'post', confirm: 'Create new codes', note: 'Your current recovery codes stop working. The new ones are shown once.' };

    return (
        <AdminLayout title="Security">
            <PageHeader title="Security" description="Two-factor authentication, where you are signed in, and the login history of the admin panel." />

            <Card
                title="Two-factor authentication"
                padded
                aside={<Pill tone={tf.enabled ? 'good' : 'bad'} dot>{tf.enabled ? `On · ${tf.method === 'email' ? 'Email codes' : 'Authenticator app'}` : 'Off'}</Pill>}
            >
                {appSetup ? (
                    <div className="flex flex-wrap items-start gap-6">
                        <div className="rounded-lg border border-line bg-white p-2.5"><QRCodeSVG value={appSetup.otpauth_uri} size={156} level="M" /></div>
                        <div className="grid gap-3">
                            <p className="max-w-sm text-[13.5px] text-muted">Scan the QR code with your authenticator app, or enter this key by hand: <span className="select-all font-mono font-semibold text-ink">{appSetup.secret}</span></p>
                            <CodeForm url="/admin/security/two-factor/app/confirm" label="6-digit code from the app" submit="Confirm and switch" />
                        </div>
                    </div>
                ) : emailPending ? (
                    <CodeForm url="/admin/security/two-factor/email/confirm" label="6-digit code we emailed you" hint={`Sent to ${tf.email}. It expires in 10 minutes.`} submit="Confirm and switch" />
                ) : (
                    <div className="grid gap-4">
                        <p className="max-w-2xl text-[13.5px] text-muted">
                            {tf.enabled
                                ? tf.method === 'email'
                                    ? `When you sign in, we email a 6-digit code to ${tf.email}.`
                                    : 'When you sign in, you enter a 6-digit code from your authenticator app.'
                                : 'Two-factor authentication is off. Your account is protected by your password only.'}{' '}
                            {tf.enabled && `You have ${tf.recovery_codes_left} recovery code(s) left for when you cannot get a code.`}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {tf.method !== 'app' && <Button variant={tf.enabled ? 'ghost' : 'primary'} onClick={() => setAction(toApp)}>{tf.enabled ? 'Switch to authenticator app' : 'Use an authenticator app'}</Button>}
                            {tf.method !== 'email' && <Button onClick={() => setAction(toEmail)}>{tf.enabled ? 'Switch to email codes' : 'Use email codes'}</Button>}
                            {tf.enabled && <Button onClick={() => setAction(codes)}>New recovery codes</Button>}
                            {tf.enabled && !tf.required && <Button variant="quiet" className="!text-bad" onClick={() => setAction(off)}>Turn off 2FA</Button>}
                        </div>
                        {tf.required && <p className="text-[12.5px] text-muted">Two-factor authentication is required for every platform admin, so it can be changed but not turned off.</p>}
                    </div>
                )}
            </Card>

            <Card
                className="mt-4"
                title={seesEveryone ? 'Active sessions (all admins)' : 'Where you are signed in'}
                aside={others > 0 && <Button size="sm" onClick={() => window.confirm(`Sign out of your ${others} other session(s)?`) && router.delete('/admin/security/sessions', { preserveScroll: true })}>Sign out my other sessions</Button>}
            >
                <Table head={[...(seesEveryone ? ['Admin'] : []), 'Device', 'IP address', 'Signed in', 'Last active', '']} empty="No active sessions.">
                    {sessions.map((s) => (
                        <tr key={s.id}>
                            {seesEveryone && <td><div className="font-semibold">{s.admin ?? '—'}</div><div className="text-[12.5px] text-muted">{s.admin_email}</div></td>}
                            <td>{device(s)} {s.current && <Pill tone="brand">This device</Pill>}</td>
                            <td className="font-mono text-[12.5px]">{where(s)}</td>
                            <td className="text-muted">{s.signed_in_at ? dateTime(s.signed_in_at) : '—'}</td>
                            <td className="text-muted">{s.current ? 'Now' : relative(s.last_active_at)}</td>
                            <td className="text-right">
                                {!s.current && <Button size="sm" variant="quiet" onClick={() => window.confirm('Sign this session out?') && router.delete(`/admin/security/sessions/${s.id}`, { preserveScroll: true })}>Sign out</Button>}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>

            <Card className="mt-4" title={seesEveryone ? 'Login history (all admins)' : 'Your login history'}>
                <Table head={['When', ...(seesEveryone ? ['Admin'] : []), 'Event', 'Verified by', 'Device', 'IP address']} empty="No sign-ins recorded yet.">
                    {history.map((h) => {
                        const [label, tone] = EVENT[h.event] ?? [h.event, 'grey' as Tone];

                        return (
                            <tr key={h.id}>
                                <td className="whitespace-nowrap text-muted">{h.at ? dateTime(h.at) : '—'}</td>
                                {seesEveryone && <td><div className="font-semibold">{h.admin ?? 'Unknown'}</div><div className="text-[12.5px] text-muted">{h.email}</div></td>}
                                <td><Pill tone={tone}>{label}</Pill></td>
                                <td className="text-muted">{h.method ? (METHOD[h.method] ?? h.method) : '—'}</td>
                                <td>{device(h)}</td>
                                <td className="font-mono text-[12.5px]">{where(h)}</td>
                            </tr>
                        );
                    })}
                </Table>
            </Card>

            <Card className="mt-4" title="Email delivery" padded>
                <div className="flex flex-wrap items-center gap-3">
                    <p className="min-w-0 flex-1 text-[13.5px] text-muted">
                        System emails are sent {mail.mailer === 'log' || mail.mailer === 'array' ? <span className="font-semibold text-bad">nowhere yet (mailer “{mail.mailer}”: emails are only written to the log)</span> : <>through <span className="font-semibold text-ink">{mail.mailer.toUpperCase()}</span></>} from <span className="font-semibold text-ink">{mail.from}</span>. Send yourself a test before relying on email codes.
                    </p>
                    <Button disabled={testing} onClick={() => router.post('/admin/security/test-email', {}, { preserveScroll: true, onStart: () => setTesting(true), onFinish: () => setTesting(false) })}>
                        {testing ? 'Sending…' : 'Send test email'}
                    </Button>
                </div>
            </Card>

            <PasswordModal action={action} onClose={() => setAction(null)} />
        </AdminLayout>
    );
}
