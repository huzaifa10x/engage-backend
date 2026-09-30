import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Field, Input } from '../../components/ui';
import AuthLayout from '../../layouts/AuthLayout';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/admin/login', { onFinish: () => form.reset('password') });
    };

    return (
        <AuthLayout title="Sign in" subtitle="For 10X staff. Every action here is recorded in the audit log.">
            <form onSubmit={submit} className="space-y-4">
                <Field label="Email" error={form.errors.email}>
                    <Input type="email" autoComplete="username" autoFocus value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                </Field>
                <Field label="Password" error={form.errors.password}>
                    <Input type="password" autoComplete="current-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </Field>
                <label className="flex items-center gap-2 text-[13.5px] text-ink-2">
                    <input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />
                    Keep me signed in on this device
                </label>
                <Button variant="primary" className="w-full" disabled={form.processing}>
                    Continue
                </Button>
            </form>
        </AuthLayout>
    );
}
