import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, Card, Field, Input, Kpi, Modal, PageHeader, Pagination, Pill, Select, Table, Textarea } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { date, money } from '../../lib/format';
import type { Paginated, SharedProps, Tone } from '../../types';

type InvoiceRow = {
    id: string;
    number: string | null;
    status: string;
    currency: string;
    subtotal_minor: number;
    tax_minor: number;
    total_minor: number;
    amount_paid_minor: number;
    amount_refunded_minor: number;
    issued_at: string | null;
    invoice_pdf: string | null;
    tenant_id: string;
    tenant: string;
};

const tone = (i: InvoiceRow): [string, Tone] =>
    i.amount_refunded_minor > 0
        ? [i.amount_refunded_minor >= i.amount_paid_minor ? 'Refunded' : 'Partly refunded', 'info']
        : i.status === 'paid'
          ? ['Paid', 'good']
          : i.status === 'open'
            ? ['Payment due', 'warn']
            : i.status === 'uncollectible'
              ? ['Unpaid', 'bad']
              : ['Void', 'grey'];

function RefundModal({ invoice, onClose }: { invoice: InvoiceRow | null; onClose: () => void }) {
    const form = useForm({ amount: '', reason: '' });
    const refundable = invoice ? (invoice.amount_paid_minor - invoice.amount_refunded_minor) / 100 : 0;
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!invoice) return;
        form.post(`/admin/invoices/${invoice.id}/refund`, { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } });
    };

    return (
        <Modal
            open={invoice !== null}
            onClose={onClose}
            title={`Refund invoice ${invoice?.number ?? ''}`}
            footer={<><Button onClick={onClose}>Cancel</Button><Button variant="danger" form="refund-form" disabled={form.processing}>{form.processing ? 'Refunding…' : 'Refund'}</Button></>}
        >
            <form id="refund-form" onSubmit={submit} className="grid gap-4">
                <p className="text-[13.5px] text-muted">The money goes back to the customer’s card through Stripe. This cannot be undone.</p>
                <Field label="Amount (USD)" error={errors.amount} hint={`Leave empty to refund the full ${refundable.toFixed(2)}.`}>
                    <Input type="number" min={0.01} max={refundable} step="0.01" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} placeholder={refundable.toFixed(2)} />
                </Field>
                <Field label="Reason" error={errors.reason} hint="Recorded in the audit log.">
                    <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} maxLength={200} required />
                </Field>
            </form>
        </Modal>
    );
}

export default function InvoicesIndex({ invoices, status, totals }: { invoices: Paginated<InvoiceRow>; status: string; totals: { paid_minor: number; refunded_minor: number; open_minor: number } }) {
    const canManage = (usePage<SharedProps>().props.auth.admin?.abilities ?? []).includes('billing.manage');
    const [refunding, setRefunding] = useState<InvoiceRow | null>(null);

    return (
        <AdminLayout title="Invoices">
            <PageHeader title="Invoices" description="Every invoice issued through Stripe, across all companies. Refunds are sent to Stripe from here." />
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Kpi label="Collected" value={money(totals.paid_minor)} />
                <Kpi label="Refunded" value={money(totals.refunded_minor)} />
                <Kpi label="Awaiting payment" value={money(totals.open_minor)} />
            </div>
            <Card
                className="mt-4"
                title="All invoices"
                aside={
                    <Select className="!w-44" value={status} aria-label="Status" onChange={(e) => router.get('/admin/invoices', e.target.value ? { status: e.target.value } : {}, { preserveState: true })}>
                        <option value="">All statuses</option>
                        <option value="paid">Paid</option>
                        <option value="open">Payment due</option>
                        <option value="uncollectible">Unpaid</option>
                        <option value="void">Void</option>
                    </Select>
                }
            >
                <Table head={['Invoice', 'Company', 'Date', 'Amount', 'VAT', 'Total', 'Status', '']} empty="No invoices yet. They appear after the first payment.">
                    {invoices.data.map((i) => {
                        const [label, t] = tone(i);
                        const refundable = i.amount_paid_minor - i.amount_refunded_minor;

                        return (
                            <tr key={i.id}>
                                <td className="font-semibold">{i.invoice_pdf ? <a href={i.invoice_pdf} target="_blank" rel="noreferrer" className="hover:text-brand">{i.number ?? '—'}</a> : (i.number ?? '—')}</td>
                                <td><Link href={`/admin/companies/${i.tenant_id}`} className="hover:text-brand">{i.tenant}</Link></td>
                                <td className="text-muted">{i.issued_at ? date(i.issued_at) : '—'}</td>
                                <td className="tabular-nums">{money(i.subtotal_minor)}</td>
                                <td className="tabular-nums">{i.tax_minor ? money(i.tax_minor) : '—'}</td>
                                <td className="font-semibold tabular-nums">{money(i.total_minor)}</td>
                                <td>
                                    <Pill tone={t}>{label}</Pill>
                                    {i.amount_refunded_minor > 0 && <div className="mt-0.5 text-[12px] text-muted">{money(i.amount_refunded_minor)} refunded</div>}
                                </td>
                                <td className="text-right">{canManage && refundable > 0 && <Button size="sm" variant="quiet" onClick={() => setRefunding(i)}>Refund</Button>}</td>
                            </tr>
                        );
                    })}
                </Table>
                <Pagination prev={invoices.prev_page_url} next={invoices.next_page_url} summary={invoices.total ? `${invoices.from}–${invoices.to} of ${invoices.total}` : undefined} />
            </Card>
            <RefundModal invoice={refunding} onClose={() => setRefunding(null)} />
        </AdminLayout>
    );
}
