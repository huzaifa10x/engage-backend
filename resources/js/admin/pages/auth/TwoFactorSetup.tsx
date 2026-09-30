import { useForm } from '@inertiajs/react';
import { QRCodeSVG } from 'qrcode.react';
import type { FormEvent } from 'react';
import { Button, Field, Input } from '../../components/ui';
import AuthLayout from '../../layouts/AuthLayout';

export default function TwoFactorSetup({ email, secret, otpauth_uri }: { email: string; secret: string; otpauth_uri: string }) {
    const form = useForm({ code: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/admin/two-factor/setup', { onError: () => form.reset() });
    };

    return (
        <AuthLayout title="Set up two-factor" subtitle="Required for every platform account. Scan the code with Google Authenticator, 1Password or Authy.">
            <div className="flex flex-col items-center gap-3 rounded-lg border border-line-2 bg-canvas p-4">
                <div className="rounded-lg bg-white p-2.5">
                    <QRCodeSVG value={otpauth_uri} size={164} level="M" />
                </div>
                <div className="text-center text-[12.5px] text-muted">
                    Can't scan? Enter this key for <span className="font-semibold text-ink-2">{email}</span>
                    <div className="mt-1 select-all font-mono text-[13px] font-semibold tracking-wide text-ink">{secret}</div>
                </div>
            </div>
            <form onSubmit={submit} className="mt-4 space-y-4">
                <Field label="Enter the 6-digit code it shows" error={form.errors.code}>
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
                <Button variant="primary" className="w-full" disabled={form.processing || form.data.code.length !== 6}>
                    Turn on two-factor
                </Button>
            </form>
        </AuthLayout>
    );
}
