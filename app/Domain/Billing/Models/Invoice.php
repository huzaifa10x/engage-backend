<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Mirror of one Stripe invoice (Stripe is the system of record). Amounts are integer minor
 * units in the invoice currency.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $stripe_invoice_id
 * @property ?string $number
 * @property string $status
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property int $amount_paid_minor
 * @property int $amount_refunded_minor
 * @property ?string $description
 * @property ?string $hosted_invoice_url
 * @property ?string $invoice_pdf
 * @property ?Carbon $period_start
 * @property ?Carbon $period_end
 * @property ?Carbon $issued_at
 * @property ?Carbon $paid_at
 */
class Invoice extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'stripe_invoice_id', 'number', 'status', 'currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'amount_paid_minor',
        'amount_refunded_minor', 'description', 'hosted_invoice_url', 'invoice_pdf', 'period_start', 'period_end', 'issued_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return ['period_start' => 'datetime', 'period_end' => 'datetime', 'issued_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    /** paid | partially_refunded | refunded | open | void | uncollectible | draft */
    public function paymentStatus(): string
    {
        if ($this->amount_refunded_minor > 0) {
            return $this->amount_refunded_minor >= $this->amount_paid_minor ? 'refunded' : 'partially_refunded';
        }

        return $this->status;
    }
}
