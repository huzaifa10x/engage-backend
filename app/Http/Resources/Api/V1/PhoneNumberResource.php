<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Messaging\Services\SendThroughput;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PhoneNumber */
final class PhoneNumberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'waba_account_id' => $this->waba_account_id,
            'phone_number_id' => $this->phone_number_id,
            'display_phone_number' => $this->display_phone_number,
            'e164' => $this->getAttribute('e164'),
            'verified_name' => $this->verified_name,
            'name_status' => $this->getAttribute('name_status'),
            'status' => $this->status->value,
            'quality_rating' => $this->quality_rating,
            'messaging_limit_tier' => $this->messaging_limit_tier,
            'throughput_level' => $this->getAttribute('throughput_level'),
            'onboarding_type' => $this->onboarding_type->value,
            'coexistence_status' => $this->coexistence_status->value,
            'app_sync_expires_at' => $this->app_sync_expires_at?->toIso8601String(),
            // What this number may actually send: plan, Meta's cap and the coexistence cap combined.
            'max_mps' => app(SendThroughput::class)->limitFor($this->resource),
            'meta_max_mps' => $this->max_mps,
            'capabilities' => (object) ($this->capabilities ?? []),
            'is_official_business_account' => (bool) $this->getAttribute('is_official_business_account'),
            'last_synced_at' => $this->getAttribute('last_synced_at')?->toIso8601String(),
        ];
    }
}
