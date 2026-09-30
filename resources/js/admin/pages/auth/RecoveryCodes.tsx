import { Link } from '@inertiajs/react';
import { useState } from 'react';
import AuthLayout from '../../layouts/AuthLayout';

export default function RecoveryCodes({ codes }: { codes: string[] }) {
    const [copied, setCopied] = useState(false);

    return (
        <AuthLayout title="Save your recovery codes" subtitle="If you lose your phone, each of these signs you in once. This is the only time they are shown.">
            <ol className="grid grid-cols-2 gap-2 rounded-lg border border-line-2 bg-canvas p-4 font-mono text-[14px] font-semibold">
                {codes.map((c) => (
                    <li key={c} className="select-all">{c}</li>
                ))}
            </ol>
            <div className="mt-4 flex gap-2">
                <button
                    type="button"
                    className="h-9 flex-1 rounded-lg border border-line text-[13.5px] font-semibold text-ink-2 hover:bg-line-2"
                    onClick={() => navigator.clipboard.writeText(codes.join('\n')).then(() => setCopied(true))}
                >
                    {copied ? 'Copied' : 'Copy codes'}
                </button>
                <Link href="/admin" className="grid h-9 flex-1 place-items-center rounded-lg bg-brand text-[13.5px] font-semibold text-white hover:bg-brand-600">
                    I've saved them
                </Link>
            </div>
        </AuthLayout>
    );
}
