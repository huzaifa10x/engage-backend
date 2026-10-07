import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, Card, Field, Input, Kpi, Modal, PageHeader, Pagination, Pill, Select, Table, Textarea } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { dateTime } from '../../lib/format';
import type { Paginated, SharedProps, Tone } from '../../types';

type Inquiry = {
    id: string;
    name: string;
    email: string;
    company: string | null;
    phone: string | null;
    team_size: string | null;
    topic: 'demo' | 'contact' | 'enterprise' | string;
    message: string | null;
    source: string | null;
    status: 'new' | 'contacted' | 'closed' | string;
    admin_note: string | null;
    created_at: string | null;
    handled_at: string | null;
};

type Filters = { q: string; status: string; topic: string };

const STATUS: Record<string, [string, Tone]> = { new: ['New', 'warn'], contacted: ['Contacted', 'info'], closed: ['Closed', 'grey'] };
const TOPIC: Record<string, string> = { demo: 'Demo request', contact: 'Contact form', enterprise: 'Enterprise' };

const clean = (filters: Filters) => Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid grid-cols-[9rem_1fr] gap-3 border-b border-line py-2 text-[13.5px] last:border-b-0">
            <dt className="text-muted">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </div>
    );
}

function Detail({ inquiry, canManage, onClose }: { inquiry: Inquiry; canManage: boolean; onClose: () => void }) {
    const form = useForm({ status: inquiry.status, admin_note: inquiry.admin_note ?? '' });
    const errors = form.errors as Record<string, string | undefined>;
    const subject = encodeURIComponent(`Re: your ${inquiry.topic === 'contact' ? 'message' : 'request'} to 10X Engage`);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(`/admin/inquiries/${inquiry.id}`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={inquiry.name}
            footer={
                <>
                    <Button onClick={onClose}>Close</Button>
                    <a href={`mailto:${inquiry.email}?subject=${subject}`} className="inline-flex h-9 items-center rounded-lg border border-line px-3 text-[13.5px] font-semibold hover:bg-bg">
                        Reply by email
                    </a>
                    {canManage && (
                        <Button variant="primary" form="inquiry-form" disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save'}
                        </Button>
                    )}
                </>
            }
        >
            <dl>
                <Row label="Received">{dateTime(inquiry.created_at)}</Row>
                <Row label="Type">{TOPIC[inquiry.topic] ?? inquiry.topic}</Row>
                <Row label="Email">
                    <a href={`mailto:${inquiry.email}`} className="font-semibold hover:text-brand">
                        {inquiry.email}
                    </a>
                </Row>
                {inquiry.phone && <Row label="Phone">{inquiry.phone}</Row>}
                {inquiry.company && <Row label="Company">{inquiry.company}</Row>}
                {inquiry.team_size && <Row label="Numbers / clients">{inquiry.team_size}</Row>}
                {inquiry.source && <Row label="Sent from">{inquiry.source}</Row>}
                <Row label="Message">{inquiry.message ? <span className="whitespace-pre-wrap">{inquiry.message}</span> : <span className="text-muted">No message</span>}</Row>
                {inquiry.handled_at && <Row label="Last handled">{dateTime(inquiry.handled_at)}</Row>}
            </dl>

            <form id="inquiry-form" onSubmit={submit} className="mt-4 grid gap-4 border-t border-line pt-4">
                <Field label="Status" error={errors.status}>
                    <Select value={form.data.status} disabled={!canManage} onChange={(e) => form.setData('status', e.target.value)}>
                        <option value="new">New</option>
                        <option value="contacted">Contacted</option>
                        <option value="closed">Closed</option>
                    </Select>
                </Field>
                <Field label="Internal note" error={errors.admin_note} hint="Only the platform team sees this.">
                    <Textarea rows={3} maxLength={4000} value={form.data.admin_note} disabled={!canManage} onChange={(e) => form.setData('admin_note', e.target.value)} />
                </Field>
            </form>
        </Modal>
    );
}

export default function InquiriesIndex({
    inquiries,
    filters,
    counts,
}: {
    inquiries: Paginated<Inquiry>;
    filters: Filters;
    counts: { new: number; contacted: number; closed: number; last_7_days: number };
}) {
    const canManage = (usePage<SharedProps>().props.auth.admin?.abilities ?? []).includes('inquiries.manage');
    const [open, setOpen] = useState<Inquiry | null>(null);
    const [q, setQ] = useState(filters.q);

    const go = (next: Partial<Filters>) => router.get('/admin/inquiries', clean({ ...filters, q, ...next }), { preserveState: true, replace: true });
    const exportUrl = `/admin/inquiries/export?${new URLSearchParams(clean({ ...filters, q })).toString()}`;

    return (
        <AdminLayout title="Inquiries">
            <PageHeader
                title="Inquiries"
                description="Demo and contact requests sent from the website. Each one is also emailed to the sales inbox."
                actions={
                    <a href={exportUrl} className="inline-flex h-9 items-center rounded-lg border border-line bg-card px-3 text-[13.5px] font-semibold hover:bg-bg">
                        Export CSV
                    </a>
                }
            />
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Kpi label="New" value={counts.new} hint="Waiting for a reply" />
                <Kpi label="Contacted" value={counts.contacted} />
                <Kpi label="Closed" value={counts.closed} />
                <Kpi label="Last 7 days" value={counts.last_7_days} hint="Received" />
            </div>

            <Card
                className="mt-4"
                title="All inquiries"
                aside={
                    <form
                        className="flex flex-wrap items-center gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            go({});
                        }}
                    >
                        <Input className="!w-56" type="search" placeholder="Name, email or company" aria-label="Search" value={q} onChange={(e) => setQ(e.target.value)} />
                        <Select className="!w-40" aria-label="Type" value={filters.topic} onChange={(e) => go({ topic: e.target.value })}>
                            <option value="">All types</option>
                            <option value="demo">Demo requests</option>
                            <option value="contact">Contact form</option>
                            <option value="enterprise">Enterprise</option>
                        </Select>
                        <Select className="!w-36" aria-label="Status" value={filters.status} onChange={(e) => go({ status: e.target.value })}>
                            <option value="">All statuses</option>
                            <option value="new">New</option>
                            <option value="contacted">Contacted</option>
                            <option value="closed">Closed</option>
                        </Select>
                    </form>
                }
            >
                <Table head={['Received', 'From', 'Company', 'Type', 'Numbers / clients', 'Sent from', 'Status', '']} empty="No inquiries match. New ones appear here as soon as a form is sent on the website.">
                    {inquiries.data.map((i) => {
                        const [label, tone] = STATUS[i.status] ?? [i.status, 'grey'];

                        return (
                            <tr key={i.id} className="cursor-pointer hover:bg-bg" onClick={() => setOpen(i)}>
                                <td className="whitespace-nowrap text-muted">{dateTime(i.created_at)}</td>
                                <td>
                                    <div className="font-semibold">{i.name}</div>
                                    <div className="text-[12.5px] text-muted">{i.email}</div>
                                </td>
                                <td>{i.company ?? '—'}</td>
                                <td>{TOPIC[i.topic] ?? i.topic}</td>
                                <td>{i.team_size ?? '—'}</td>
                                <td className="text-muted">{i.source ?? '—'}</td>
                                <td>
                                    <Pill tone={tone}>{label}</Pill>
                                </td>
                                <td className="text-right">
                                    <Button
                                        size="sm"
                                        variant="quiet"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setOpen(i);
                                        }}
                                    >
                                        View
                                    </Button>
                                </td>
                            </tr>
                        );
                    })}
                </Table>
                <Pagination prev={inquiries.prev_page_url} next={inquiries.next_page_url} summary={inquiries.total ? `${inquiries.from}–${inquiries.to} of ${inquiries.total}` : undefined} />
            </Card>

            {open && <Detail key={open.id} inquiry={open} canManage={canManage} onClose={() => setOpen(null)} />}
        </AdminLayout>
    );
}
