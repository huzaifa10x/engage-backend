<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WabaAccount */
final class WabaAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'waba_id' => $this->waba_id,
            'name' => $this->name,
            'business_name' => $this->business_name,
            'meta_business_id' => $this->getAttribute('meta_business_id'),
            'currency' => $this->getAttribute('currency'),
            'account_review_status' => $this->getAttribute('account_review_status'),
            'ban_state' => $this->getAttribute('ban_state'),
            'status' => $this->status->value,
            'is_subscribed_to_webhooks' => $this->is_subscribed_to_webhooks,
            'connected_at' => $this->connected_at?->toIso8601String(),
            'disconnected_at' => $this->disconnected_at?->toIso8601String(),
            // manual | partner_removed | offboarded | access_revoked
            'disconnect_reason' => $this->getAttribute('disconnect_reason'),
            'phone_numbers' => PhoneNumberResource::collection($this->whenLoaded('phoneNumbers')),
        ];
    }
}
