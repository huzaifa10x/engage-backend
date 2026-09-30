import { Link } from '@inertiajs/react';
import { useEffect, useRef, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import type { Tone } from '../types';

const cx = (...c: (string | false | null | undefined)[]) => c.filter(Boolean).join(' ');

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'ghost' | 'danger' | 'quiet'; size?: 'sm' | 'md' };

export function Button({ variant = 'ghost', size = 'md', className, ...props }: ButtonProps) {
    return (
        <button
            {...props}
            className={cx(
                'inline-flex items-center justify-center gap-1.5 rounded-lg font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50',
                size === 'sm' ? 'h-8 px-3 text-[13px]' : 'h-9 px-3.5 text-[13.5px]',
                variant === 'primary' && 'bg-brand text-white hover:bg-brand-600',
                variant === 'ghost' && 'border border-line bg-white text-ink-2 hover:bg-line-2',
                variant === 'danger' && 'bg-bad text-white hover:bg-red-700',
                variant === 'quiet' && 'text-brand hover:bg-brand-50',
                className,
            )}
        />
    );
}

const toneClass: Record<Tone, string> = {
    good: 'bg-good-bg text-good',
    warn: 'bg-warn-bg text-warn',
    bad: 'bg-bad-bg text-bad',
    info: 'bg-info-bg text-info',
    brand: 'bg-brand-50 text-brand-600',
    grey: 'bg-grey-bg text-muted',
};

export function Pill({ tone = 'grey', dot = false, children }: { tone?: Tone; dot?: boolean; children: ReactNode }) {
    return (
        <span className={cx('inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[12px] font-semibold', toneClass[tone])}>
            {dot && <span className="size-1.5 rounded-full bg-current" aria-hidden />}
            {children}
        </span>
    );
}

export function Card({ title, aside, children, className, padded = false }: { title?: ReactNode; aside?: ReactNode; children: ReactNode; className?: string; padded?: boolean }) {
    return (
        <section className={cx('rounded-card border border-line bg-white shadow-sm', className)}>
            {(title || aside) && (
                <header className="flex items-center justify-between gap-3 border-b border-line-2 px-4 py-3">
                    {title && <h3 className="text-[14.5px] font-bold">{title}</h3>}
                    {aside}
                </header>
            )}
            <div className={padded ? 'p-4' : undefined}>{children}</div>
        </section>
    );
}

export function PageHeader({ title, description, actions }: { title: string; description?: ReactNode; actions?: ReactNode }) {
    return (
        <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 className="text-[22px] font-bold tracking-tight">{title}</h1>
                {description && <p className="mt-0.5 text-muted">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

export function Kpi({ label, value, hint }: { label: string; value: ReactNode; hint?: ReactNode }) {
    return (
        <div className="rounded-card border border-line bg-white p-4 shadow-sm">
            <div className="text-[13px] font-medium text-muted">{label}</div>
            <div className="mt-1 text-[26px] font-bold tracking-tight tabular-nums">{value}</div>
            {hint && <div className="mt-0.5 text-[12.5px] text-muted">{hint}</div>}
        </div>
    );
}

export function Table({ head, children, empty }: { head: ReactNode[]; children: ReactNode; empty?: ReactNode }) {
    const hasRows = Array.isArray(children) ? children.length > 0 : Boolean(children);
    return (
        <div className="overflow-x-auto">
            <table className="w-full border-collapse text-left">
                <thead>
                    <tr className="border-b border-line-2 text-[12.5px] text-muted">
                        {head.map((h, i) => (
                            <th key={i} className="px-4 py-2.5 font-semibold">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="[&>tr]:border-b [&>tr]:border-line-2 [&>tr:last-child]:border-0 [&_td]:px-4 [&_td]:py-2.5">{children}</tbody>
            </table>
            {!hasRows && empty && <div className="px-4 py-10 text-center text-muted">{empty}</div>}
        </div>
    );
}

type FieldProps = { label: string; error?: string; hint?: ReactNode; children: ReactNode };

export function Field({ label, error, hint, children }: FieldProps) {
    return (
        <label className="block">
            <span className="mb-1 block text-[13px] font-semibold text-ink-2">{label}</span>
            {children}
            {hint && !error && <span className="mt-1 block text-[12.5px] text-muted">{hint}</span>}
            {error && <span className="mt-1 block text-[12.5px] font-medium text-bad">{error}</span>}
        </label>
    );
}

const control = 'w-full rounded-lg border border-line bg-white px-3 text-[14px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-3 focus:ring-brand-100';

export const Input = ({ className, ...p }: InputHTMLAttributes<HTMLInputElement>) => <input {...p} className={cx(control, 'h-9', className)} />;
export const Select = ({ className, ...p }: SelectHTMLAttributes<HTMLSelectElement>) => <select {...p} className={cx(control, 'h-9', className)} />;
export const Textarea = ({ className, ...p }: TextareaHTMLAttributes<HTMLTextAreaElement>) => <textarea {...p} className={cx(control, 'py-2', className)} rows={p.rows ?? 3} />;

export function Modal({ open, onClose, title, children, footer }: { open: boolean; onClose: () => void; title: string; children: ReactNode; footer?: ReactNode }) {
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        const d = ref.current;
        if (!d) return;
        if (open && !d.open) d.showModal();
        if (!open && d.open) d.close();
    }, [open]);

    return (
        <dialog
            ref={ref}
            onClose={onClose}
            className="m-auto w-[min(520px,calc(100vw-2rem))] rounded-card border border-line bg-white p-0 text-ink shadow-lg backdrop:bg-ink/40"
        >
            <div className="border-b border-line-2 px-5 py-3.5 text-[15px] font-bold">{title}</div>
            <div className="space-y-3.5 px-5 py-4">{children}</div>
            {footer && <div className="flex justify-end gap-2 border-t border-line-2 px-5 py-3">{footer}</div>}
        </dialog>
    );
}

export function Pagination({ prev, next, summary }: { prev: string | null; next: string | null; summary?: ReactNode }) {
    if (!prev && !next) return null;
    return (
        <div className="flex items-center justify-between border-t border-line-2 px-4 py-3 text-[13px] text-muted">
            <span>{summary}</span>
            <div className="flex gap-2">
                {prev ? <Link href={prev} preserveScroll className="font-semibold text-brand">Previous</Link> : <span className="opacity-40">Previous</span>}
                {next ? <Link href={next} preserveScroll className="font-semibold text-brand">Next</Link> : <span className="opacity-40">Next</span>}
            </div>
        </div>
    );
}

export function Initials({ name, size = 32 }: { name: string; size?: number }) {
    const initials = name.split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
    return (
        <span style={{ width: size, height: size }} className="inline-grid shrink-0 place-items-center rounded-lg bg-brand-50 text-[12px] font-bold text-brand-600">
            {initials}
        </span>
    );
}
