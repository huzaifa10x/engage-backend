<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe billing: the customer's invoice details on the tenant, Stripe price IDs on the plan
 * catalog, a mirror of Stripe invoices per tenant, and processed-event IDs for idempotency.
 * Stripe is the system of record for money; these tables only mirror it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('legal_name', 190)->nullable();           // company name printed on invoices
            $table->string('tax_trn', 32)->nullable();               // customer's VAT TRN (UAE: 15 digits)
            $table->string('stripe_customer_id', 64)->nullable()->unique();
        });

        Schema::table('plan_versions', function (Blueprint $table) {
            $table->string('stripe_price_monthly_id', 64)->nullable()->index();
            $table->string('stripe_price_yearly_id', 64)->nullable()->index();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('stripe_invoice_id', 64)->unique();
            $table->string('number', 64)->nullable();
            $table->string('status', 16);                            // draft | open | paid | void | uncollectible
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('amount_paid_minor')->default(0);
            $table->bigInteger('amount_refunded_minor')->default(0);
            $table->string('description', 255)->nullable();
            $table->text('hosted_invoice_url')->nullable();
            $table->text('invoice_pdf')->nullable();
            $table->timestampTz('period_start')->nullable();
            $table->timestampTz('period_end')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'issued_at']);
        });
        TenantSchema::enableRls('invoices');

        // Platform table (no tenant): Stripe event IDs already handled.
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('type', 64);
            $table->timestampTz('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('invoices');
        Schema::table('plan_versions', function (Blueprint $table) {
            $table->dropColumn(['stripe_price_monthly_id', 'stripe_price_yearly_id']);
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'tax_trn', 'stripe_customer_id']);
        });
    }
};
