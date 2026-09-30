import { Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, Card, Input, PageHeader, Pill, Select } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { dateTime, humanize } from '../../lib/format';
import type { Tone } from '../../types';

type Entry = {
    id: string; action: string; actor_type: string; actor: string; tenant_id: string | null; tenant: string | null;
    entity_type: string | null; entity_id: string | null; before: Record<string, unknown> | null; after: Record<string, unknown> | null;
    meta: Record<string, unknown> | null; ip: string | null; request_id: string | null; created_at: string;
};
type Props = { entries: Entry[]; nextCursor: string | null; prevCursor: string | null; filters: { action?: string; actor_type?: string; tenant_id?: string } };

const actorTone: Record<string, Tone> = { admin: 'brand', user: 'info', system: 'grey', api: 'warn' };

export default function AuditIndex({ entries, nextCursor, prevCursor, filters }: Props) {
    const [action, setAction] = useState(filters.action ?? '');
    const [open, setOpen] = useState<string | null>(null);
    const go = (next: Record<string, string | undefined>) => router.get('/admin/audit-log', { ...filters, ...next }, { preserveState: true });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        go({ action: action || undefined, cursor: undefined });
    };

    return (
        <AdminLayout title="Audit log">
            <PageHeader title="Audit log" description="Who changed what, across every company and the platform itself. Entries can't be edited or deleted." />

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <form onSubmit={submit} className="w-full sm:w-72">
                    <Input type="search" placeholder="Action starts with, e.g. tenant." value={action} onChange={(e) => setAction(e.target.value)} />
                </form>
                <Select className="w-auto" value={filters.actor_type ?? ''} onChange={(e) => go({ actor_type: e.target.value || undefined, cursor: undefined })} aria-label="Actor">
                    <option value="">Anyone</option>
                    <option value="admin">10X staff</option>
                    <option value="user">Client users</option>
                    <option value="system">System</option>
                </Select>
                {filters.tenant_id && (
                    <Button size="sm" onClick={() => go({ tenant_id: undefined, cursor: undefined })}>Showing one company. Show all</Button>
                )}
            </div>

            <Card>
                <ul className="divide-y divide-line-2">
                    {entries.map((e) => (
                        <li key={e.id}>
                            <button className="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-canvas" onClick={() => setOpen(open === e.id ? null : e.id)} aria-expanded={open === e.id}>
                                <div className="min-w-0 flex-1">
                                    <div className="text-[13.5px]">
                                        <span className="font-semibold">{e.actor}</span> <span className="text-ink-2">{humanize(e.action)}</span>
                                        {e.tenant && (
                                            <> in <Link href={`/admin/companies/${e.tenant_id}`} className="font-semibold text-brand" onClick={(ev) => ev.stopPropagation()}>{e.tenant}</Link></>
                                        )}
                                    </div>
                                    <div className="mt-0.5 font-mono text-[12px] text-muted">{e.action}{e.entity_type ? `  ${e.entity_type}:${e.entity_id}` : ''}</div>
                                </div>
                                <Pill tone={actorTone[e.actor_type] ?? 'grey'}>{humanize(e.actor_type)}</Pill>
                                <span className="w-32 shrink-0 text-right text-[12.5px] text-muted">{dateTime(e.created_at)}</span>
                            </button>
                            {open === e.id && (
                                <div className="grid gap-3 bg-canvas px-4 py-3 md:grid-cols-3">
                                    {(['before', 'after', 'meta'] as const).map((k) => (
                                        <div key={k}>
                                            <div className="mb-1 text-[12px] font-semibold text-muted">{humanize(k)}</div>
                                            <pre className="overflow-x-auto rounded-lg border border-line bg-white p-2.5 font-mono text-[12px]">{e[k] ? JSON.stringify(e[k], null, 2) : '—'}</pre>
                                        </div>
                                    ))}
                                    <div className="text-[12px] text-muted md:col-span-3">IP {e.ip ?? '—'}, request {e.request_id ?? '—'}</div>
                                </div>
                            )}
                        </li>
                    ))}
                    {entries.length === 0 && <li className="px-4 py-10 text-center text-muted">Nothing matches these filters.</li>}
                </ul>
                {(prevCursor || nextCursor) && (
                    <div className="flex justify-end gap-3 border-t border-line-2 px-4 py-3 text-[13px] font-semibold">
                        <button disabled={!prevCursor} className="text-brand disabled:opacity-40" onClick={() => go({ cursor: prevCursor ?? undefined })}>Newer</button>
                        <button disabled={!nextCursor} className="text-brand disabled:opacity-40" onClick={() => go({ cursor: nextCursor ?? undefined })}>Older</button>
                    </div>
                )}
            </Card>
        </AdminLayout>
    );
}
