<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Read-only for UI gating/upgrade prompts. Enforcement happens server-side in actions. */
final class EntitlementController extends Controller
{
    public function index(TenantContext $context, EntitlementService $entitlements): JsonResponse
    {
        $tenant = $context->tenant();
        $set = $entitlements->for($tenant);

        $features = [];
        foreach (FeatureKey::cases() as $key) {
            $entitlement = $set->get($key);
            $features[$key->value] = [
                'label' => $key->label(),
                'type' => $entitlement->type->value,
                'enabled' => $entitlement->enabled,
                'limit' => $entitlement->limit,
                'unlimited' => $entitlement->isUnlimited(),
                'used' => $entitlement->type->isQuantified() ? $entitlements->usage($tenant, $key) : null,
                'unit' => $key->unit(),
                'config' => (object) $entitlement->config,
            ];
        }

        return response()->json(['data' => [
            'plan' => ['key' => $set->planKey, 'name' => $set->planName, 'version' => $set->planVersion],
            'subscription' => ['status' => $set->subscriptionStatus, 'trial_ends_at' => $set->trialEndsAt],
            'features' => $features,
        ]]);
    }
}
