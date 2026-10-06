<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
final class TenantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'currency' => $this->currency,
            'country' => $this->country,
            'billing_email' => $this->billing_email,
            'legal_name' => $this->legal_name,
            'website' => $this->website,
            'phone' => $this->phone,
            'industry' => $this->industry,
            'company_size' => $this->company_size,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city' => $this->city,
            'region' => $this->region,
            'postal_code' => $this->postal_code,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
