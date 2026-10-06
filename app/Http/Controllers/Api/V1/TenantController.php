<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Billing\StripeBilling;
use App\Domain\Audit\AuditLogger;
use App\Domain\Messaging\Services\AutoReply;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateTenantRequest;
use App\Http\Resources\Api\V1\TenantResource;
use App\Infrastructure\Stripe\StripeClient;
use App\Infrastructure\Stripe\StripeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /** The automatic reply to incoming messages (off by default). */
    public function autoReply(): JsonResponse
    {
        return response()->json(['data' => AutoReply::settings($this->context->tenant())]);
    }

    public function updateAutoReply(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message' => ['required_if:enabled,true', 'nullable', 'string', 'max:1000'],
            'cooldown_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'when' => ['sometimes', 'in:always,outside_hours'],
        ]);
        $tenant = $this->context->tenant();
        $settings = $tenant->settings ?? [];
        $before = (array) ($settings['auto_reply'] ?? []);
        $settings['auto_reply'] = array_merge($before, array_filter($data, fn ($v) => $v !== null));
        $tenant->forceFill(['settings' => $settings])->save();
        $audit->record('tenant.auto_reply_updated', $tenant, before: $before, after: $settings['auto_reply']);

        return $this->autoReply();
    }
}
