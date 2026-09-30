import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, Field, Input } from '../../components/ui';
import AuthLayout from '../../layouts/AuthLayout';

export default function TwoFactorChallenge({ email }: { email: string }) {
    const [useRecovery, setUseRecovery] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => (useRecovery ? { recovery_code: d.recovery_code } : { code: d.code }));
        form.post('/admin/two-factor/challenge', { onError: () => form.reset() });
    };

    return (
        <AuthLayout title="Two-factor check" subtitle={`Signing in as ${email}`}>
            <form onSubmit={submit} className="space-y-4">
                {useRecovery ? (
                    <Field label="Recovery code" error={form.errors.recovery_code} hint="Each recovery code works once.">
                        <Input autoFocus autoComplete="off" placeholder="xxxxxx-xxxxxx" value={form.data.recovery_code} onChange={(e) => form.setData('recovery_code', e.target.value)} />
                    </Field>
                ) : (
                    <Field label="6-digit code from your authenticator app" error={form.errors.code}>
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
                <button type="button" onClick={() => setUseRecovery((v) => !v)} className="w-full text-center text-[13px] font-semibold text-brand">
                    {useRecovery ? 'Use my authenticator app instead' : 'Lost your device? Use a recovery code'}
                </button>
            </form>
        </AuthLayout>
    );
}
