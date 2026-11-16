<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Application\WhatsApp\EmbeddedSignup;
use App\Application\WhatsApp\MarketingMessages;
use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Tenancy\TenantContext;
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
            // Meta refused this account for a missing permission (error 200): reconnect to grant it again.
            'needs_reconnect' => $this->permission_error_at === null ? null : [
                'reason' => 'permission_missing',
                'since' => $this->permission_error_at->toIso8601String(),
                'meta_code' => $this->permission_error['code'] ?? null,
            ],
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
            // Conversions to Meta: null for workspaces not switched on (they see no change). The status
            // comes from the stored debug_token check; unknown until the account is checked once.
            'conversions' => $this->conversions(),
            'phone_numbers' => PhoneNumberResource::collection($this->whenLoaded('phoneNumbers')),
        ];
    }

    /** @return ?array{status: string, checked_at: ?string, can_reconnect: bool, coexistence: bool} */
    private function conversions(): ?array
    {
        $tenant = app(TenantContext::class)->tenantOrNull();
        if ($tenant === null || ! ConversionsAccess::allowed($tenant)) {
            return null;
        }
        $permission = $this->getAttribute('events_permission');
        $coexistence = $this->relationLoaded('phoneNumbers') && $this->phoneNumbers->contains(fn ($n) => $n->isCoexistence());

        return [
            'status' => $permission === null ? 'unknown' : ($permission ? 'enabled' : 'not_enabled'),
            'checked_at' => $this->events_permission_checked_at?->toIso8601String(),
            'can_reconnect' => EmbeddedSignup::eventsConfigId($coexistence) !== null,
            'coexistence' => $coexistence, // reconnect with the coexistence flow
        ];
    }
}
