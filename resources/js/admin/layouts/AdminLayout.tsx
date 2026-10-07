import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import type { SharedProps } from '../types';

type NavItem = { label: string; href: string; ability: string; match: string; external?: boolean };
type NavSection = { title: string; items: NavItem[] };

const NAV: NavSection[] = [
    {
        title: 'Platform',
        items: [
            { label: 'Overview', href: '/admin', ability: 'companies.view', match: '/admin$' },
            { label: 'Companies', href: '/admin/companies', ability: 'companies.view', match: '/admin/companies' },
            { label: 'Users', href: '/admin/users', ability: 'users.view', match: '/admin/users' },
            { label: 'Numbers & health', href: '/admin/numbers', ability: 'companies.view', match: '/admin/numbers' },
        ],
    },
    {
        title: 'Revenue',
        items: [
            { label: 'Subscriptions', href: '/admin/subscriptions', ability: 'billing.view', match: '/admin/subscriptions' },
            { label: 'Plans', href: '/admin/plans', ability: 'billing.view', match: '/admin/plans' },
            { label: 'Invoices', href: '/admin/invoices', ability: 'billing.view', match: '/admin/invoices' },
            { label: 'Inquiries', href: '/admin/inquiries', ability: 'inquiries.view', match: '/admin/inquiries' },
        ],
    },
    {
        title: 'Operations',
        items: [
            { label: 'Audit log', href: '/admin/audit-log', ability: 'audit.view', match: '/admin/audit-log' },
            { label: 'Webhooks', href: '/admin/webhooks', ability: 'system.view', match: '/admin/webhooks' },
            { label: 'Platform team', href: '/admin/team', ability: 'team.manage', match: '/admin/team' },
            { label: 'Security', href: '/admin/security', ability: 'companies.view', match: '/admin/security' },
            { label: 'Queues', href: '/horizon', ability: 'system.view', match: '^$', external: true },
        ],
    },
];

export default function AdminLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, flash, env, newInquiries } = usePage<SharedProps>().props;
    const url = usePage().url.split('?')[0] ?? '';
    const [toast, setToast] = useState<{ tone: 'good' | 'bad'; text: string } | null>(null);
    const [menuOpen, setMenuOpen] = useState(false);

    useEffect(() => {
        const text = flash.success ?? flash.error;
        if (!text) return;
        setToast({ tone: flash.success ? 'good' : 'bad', text });
        const t = setTimeout(() => setToast(null), 5000);
        return () => clearTimeout(t);
    }, [flash.success, flash.error]);

    const abilities = auth.admin?.abilities ?? [];

    return (
        <div className="min-h-screen lg:grid lg:grid-cols-[232px_1fr]">
            <Head title={title} />
            <aside className={`${menuOpen ? 'block' : 'hidden'} bg-side text-side-ink lg:sticky lg:top-0 lg:block lg:h-screen`}>
                <div className="flex items-center gap-2.5 px-5 py-5">
                    <span className="grid size-8 place-items-center rounded-lg bg-brand text-[13px] font-extrabold text-white">10X</span>
                    <div>
                        <div className="text-[14px] font-bold text-white">Engage</div>
                        <div className="text-[12px] text-side-muted">Super Admin</div>
                    </div>
                </div>
                <nav className="space-y-5 px-3 pb-6" aria-label="Admin">
                    {NAV.map((section) => {
                        const items = section.items.filter((i) => abilities.includes(i.ability));
                        if (items.length === 0) return null;
                        return (
                            <div key={section.title}>
                                <div className="px-2 pb-1.5 text-[12px] font-semibold text-side-muted">{section.title}</div>
                                <ul className="space-y-0.5">
                                    {items.map((item) => {
                                        const active = new RegExp(item.match).test(url);
                                        const cls = `flex items-center rounded-lg px-2.5 py-2 text-[13.5px] font-medium ${
                                            active ? 'bg-side-2 text-white' : 'hover:bg-side-2 hover:text-white'
                                        }`;
                                        return (
                                            <li key={item.href}>
                                                {item.external ? (
                                                    <a href={item.href} target="_blank" rel="noreferrer" className={cls}>
                                                        {item.label}
                                                        <span className="ml-auto text-[11px] text-side-muted">opens Horizon</span>
                                                    </a>
                                                ) : (
                                                    <Link href={item.href} className={cls} aria-current={active ? 'page' : undefined}>
                                                        {item.label}
                                                        {item.href === '/admin/inquiries' && (newInquiries ?? 0) > 0 && (
                                                            <span className="ml-auto rounded-full bg-warn px-1.5 text-[11px] leading-5 font-bold text-white" aria-label={`${newInquiries} new`}>
                                                                {newInquiries}
                                                            </span>
                                                        )}
                                                    </Link>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        );
                    })}
                </nav>
            </aside>

            <div className="min-w-0">
                <header className="sticky top-0 z-10 flex h-14 items-center gap-3 border-b border-line bg-white/90 px-4 backdrop-blur lg:px-8">
                    <button className="rounded-lg border border-line px-2.5 py-1 text-[13px] lg:hidden" onClick={() => setMenuOpen((v) => !v)}>
                        Menu
                    </button>
                    {env !== 'production' && (
                        <span className="rounded-md bg-warn-bg px-2 py-0.5 text-[12px] font-semibold text-warn">{env}</span>
                    )}
                    <div className="ml-auto flex items-center gap-3">
                        <div className="text-right leading-tight">
                            <div className="text-[13.5px] font-semibold">{auth.admin?.name}</div>
                            <div className="text-[12px] text-muted">{auth.admin?.role_label}</div>
                        </div>
                        <button onClick={() => router.post('/admin/logout')} className="rounded-lg px-2.5 py-1.5 text-[13px] font-semibold text-muted hover:bg-line-2 hover:text-ink">
                            Sign out
                        </button>
                    </div>
                </header>
                <main className="mx-auto max-w-[1240px] px-4 py-6 lg:px-8">{children}</main>
            </div>

            {toast && (
                <div
                    role="status"
                    className={`fixed right-5 bottom-5 z-50 max-w-sm rounded-lg px-4 py-3 text-[13.5px] font-semibold shadow-lg ${
                        toast.tone === 'good' ? 'bg-ink text-white' : 'bg-bad text-white'
                    }`}
                >
                    {toast.text}
                </div>
            )}
        </div>
    );
}
