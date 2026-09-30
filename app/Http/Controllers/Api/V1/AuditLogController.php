<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AuditLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AuditLogController extends Controller
{
    public function index(Request $request, TenantContext $context, EntitlementService $entitlements): AnonymousResourceCollection
    {
        $request->validate([
            'action' => ['sometimes', 'string', 'max:100'],
            'entity_type' => ['sometimes', 'string', 'max:64'],
        ]);

        $tenant = $context->tenant();
        $retentionDays = $entitlements->for($tenant)->get(FeatureKey::AuditLogRetentionDays)->limit;

        $logs = AuditLog::query()
            ->where('tenant_id', $tenant->getKey())
            ->when($retentionDays !== null, fn ($q) => $q->where('created_at', '>=', now()->subDays((int) $retentionDays)))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')->toString()))
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->string('entity_type')->toString()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(50);

        return AuditLogResource::collection($logs);
    }
}
