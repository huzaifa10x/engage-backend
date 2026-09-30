<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantMembership */
final class MembershipResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'joined_at' => $this->getAttribute('joined_at')?->toIso8601String(),
            'role' => RoleResource::make($this->whenLoaded('role')),
            'user' => UserResource::make($this->whenLoaded('user')),
            'tenant' => TenantResource::make($this->whenLoaded('tenant')),
        ];
    }
}
