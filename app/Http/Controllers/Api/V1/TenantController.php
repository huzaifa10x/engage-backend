<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Billing\StripeBilling;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateTenantRequest;
use App\Http\Resources\Api\V1\TenantResource;
use App\Infrastructure\Stripe\StripeClient;
use App\Infrastructure\Stripe\StripeException;
use Illuminate\Support\Facades\Log;

final class TenantController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): TenantResource
    {
        return TenantResource::make($this->context->tenant());
    }

    public function update(UpdateTenantRequest $request, AuditLogger $audit, StripeBilling $billing, StripeClient $stripe): TenantResource
    {
        $tenant = $this->context->tenant();
        $before = $tenant->only(array_keys($request->validated()));

        $tenant->fill($request->validated())->save();
        $audit->record('tenant.updated', $tenant, before: $before, after: $request->validated());

        // The billing country decides VAT: keep the Stripe customer and subscription in step.
        if ($tenant->stripe_customer_id !== null && $tenant->wasChanged(['country', 'billing_email', 'name', 'legal_name', 'phone', 'address_line1', 'address_line2', 'city', 'region', 'postal_code']) && $stripe->configured()) {
            try {
                $billing->syncCustomer($tenant);
                $billing->applyTaxToSubscription($tenant);
            } catch (StripeException $e) {
                Log::warning('Could not update the Stripe customer after a workspace change.', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }

        return TenantResource::make($tenant);
    }
}
