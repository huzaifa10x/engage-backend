<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateTenantRequest;
use App\Http\Resources\Api\V1\TenantResource;

final class TenantController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): TenantResource
    {
        return TenantResource::make($this->context->tenant());
    }

    public function update(UpdateTenantRequest $request, AuditLogger $audit): TenantResource
    {
        $tenant = $this->context->tenant();
        $before = $tenant->only(array_keys($request->validated()));

        $tenant->fill($request->validated())->save();
        $audit->record('tenant.updated', $tenant, before: $before, after: $request->validated());

        return TenantResource::make($tenant);
    }
}
