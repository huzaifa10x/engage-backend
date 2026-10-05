<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Billing\StripeBilling;
use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Super Admin → Invoices: every payment across all companies, with refunds through Stripe. */
final class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        $invoices = DB::table('invoices as i')->join('tenants as t', 't.id', '=', 'i.tenant_id')
            ->where('i.status', '!=', 'draft')
            ->when(in_array($status, ['paid', 'open', 'void', 'uncollectible'], true), fn ($q) => $q->where('i.status', $status))
            ->orderByDesc('i.issued_at')->orderByDesc('i.id')
            ->select(['i.id', 'i.number', 'i.status', 'i.currency', 'i.subtotal_minor', 'i.tax_minor', 'i.total_minor', 'i.amount_paid_minor', 'i.amount_refunded_minor',
                'i.issued_at', 'i.invoice_pdf', 't.id as tenant_id', 't.name as tenant'])
            ->paginate(25)->withQueryString();

        return Inertia::render('invoices/Index', [
            'invoices' => $invoices,
            'status' => $status,
            'totals' => [
                'paid_minor' => (int) DB::table('invoices')->where('status', 'paid')->sum('amount_paid_minor'),
                'refunded_minor' => (int) DB::table('invoices')->sum('amount_refunded_minor'),
                'open_minor' => (int) DB::table('invoices')->where('status', 'open')->sum('total_minor'),
            ],
        ]);
    }

    /** Full refund when no amount is given; otherwise a partial refund in the invoice currency. */
    public function refund(Request $request, string $invoice, StripeBilling $billing, TenantContext $context, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:1000000'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $model = $context->bypass(fn () => Invoice::query()->findOrFail($invoice));
        $amountMinor = isset($data['amount']) ? (int) round(((float) $data['amount']) * 100) : null;

        $context->bypass(fn () => $billing->refundInvoice($model, $amountMinor, $data['reason'] ?? null));
        $audit->record('invoice.refunded', null, meta: ['invoice' => $model->number, 'tenant_id' => $model->tenant_id, 'amount_minor' => $amountMinor, 'reason' => $data['reason'] ?? null]);

        return back()->with('success', 'Refund sent to Stripe. The customer usually sees it on their card within 5–10 days.');
    }
}
