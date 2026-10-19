<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Application\WhatsApp\MarketingMessages;
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
            // Marketing Messages API. Read-only: only Meta (webhook / sync) moves it to onboarded.
            'marketing_messages' => [
                'enabled' => MarketingMessages::enabled(),
                'status' => $this->mm_status->value ?? 'not_started', // not_started | eligible | request_sent | onboarded | not_eligible
                'route' => MarketingMessages::enabled() && $this->usesMarketingMessagesApi() ? 'mm_api' : 'cloud_api',
                'meta_status' => $this->getAttribute('mm_meta_status'),
                'terms_status' => $this->getAttribute('mm_terms_status'),
                'consent_source' => $this->getAttribute('mm_consent_source'),
                'requested_at' => $this->mm_requested_at?->toIso8601String(),
                'request_error' => $this->getAttribute('mm_request_error'),
                'onboarded_at' => $this->mm_onboarded_at?->toIso8601String(),
                'synced_at' => $this->mm_status_synced_at?->toIso8601String(),
            ],
            'phone_numbers' => PhoneNumberResource::collection($this->whenLoaded('phoneNumbers')),
        ];
    }
}
