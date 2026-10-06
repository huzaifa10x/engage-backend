import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, Field, Input } from '../../components/ui';
import AuthLayout from '../../layouts/AuthLayout';
import type { SharedProps } from '../../types';

export default function TwoFactorChallenge({ email, method }: { email: string; method: 'app' | 'email' | null }) {
    const { flash } = usePage<SharedProps>().props;
    const [useRecovery, setUseRecovery] = useState(false);
    const [resending, setResending] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });
    const byEmail = method === 'email';

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => (useRecovery ? { recovery_code: d.recovery_code } : { code: d.code }));
        form.post('/admin/two-factor/challenge', { onError: () => form.reset() });
    };

    const resend = () => router.post('/admin/two-factor/resend', {}, { preserveScroll: true, onStart: () => setResending(true), onFinish: () => setResending(false) });

    return (
        <AuthLayout title="Two-factor check" subtitle={`Signing in as ${email}`}>
            {flash.error && <p className="mb-3 rounded-lg bg-bad-bg px-3 py-2 text-[13px] text-bad">{flash.error}</p>}
            {flash.success && <p className="mb-3 rounded-lg bg-good-bg px-3 py-2 text-[13px] text-good">{flash.success}</p>}
            <form onSubmit={submit} className="space-y-4">
                {useRecovery ? (
                    <Field label="Recovery code" error={form.errors.recovery_code} hint="Each recovery code works once.">
                        <Input autoFocus autoComplete="off" placeholder="xxxxxx-xxxxxx" value={form.data.recovery_code} onChange={(e) => form.setData('recovery_code', e.target.value)} />
                    </Field>
                ) : (
                    <Field
                        label={byEmail ? '6-digit code we emailed you' : '6-digit code from your authenticator app'}
                        error={form.errors.code}
                        hint={byEmail ? `Sent to ${email}. It expires in 10 minutes.` : undefined}
                    >
                        <Input
                            autoFocus
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            className="text-center font-mono text-[20px] tracking-[0.4em]"
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, ''))}
                        />
                    </Field>
                )}
                <Button variant="primary" className="w-full" disabled={form.processing}>
                    Verify and sign in
                </Button>
                {byEmail && !useRecovery && (
                    <button type="button" onClick={resend} disabled={resending} className="w-full text-center text-[13px] font-semibold text-brand disabled:opacity-50">
                        {resending ? 'Sending…' : 'Send a new code'}
                    </button>
                )}
                <button type="button" onClick={() => setUseRecovery((v) => !v)} className="w-full text-center text-[13px] font-semibold text-brand">
                    {useRecovery ? (byEmail ? 'Use the emailed code instead' : 'Use my authenticator app instead') : byEmail ? 'No email? Use a recovery code' : 'Lost your device? Use a recovery code'}
                </button>
            </form>
        </AuthLayout>
    );
}
