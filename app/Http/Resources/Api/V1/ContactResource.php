<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Access\Permission;
use App\Domain\Growth\GrowthAccess;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Growth\Services\Acquisition;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contact */
final class ContactResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->displayName(),
            'name' => $this->name,
            'profile_name' => $this->profile_name,
            'username' => $this->username,
            'phone' => $this->wa_id !== null ? '+'.$this->wa_id : null,
            'wa_id' => $this->wa_id,
            'bsuid' => $this->bsuid,
            'email' => $this->email,
            'attributes' => (object) ($this->custom_fields ?? []),
            'tags' => $this->tags,
            'source' => $this->source,
            // Set for contacts that came from a WhatsApp Business app sync: the number they were synced from.
            'synced_from' => $this->whenLoaded('syncedFrom', fn () => $this->syncedFrom === null ? null : [
                'id' => $this->syncedFrom->id,
                'display_phone_number' => $this->syncedFrom->display_phone_number,
                'verified_name' => $this->syncedFrom->verified_name,
                'status' => $this->syncedFrom->status->value,
            ]),
            // CRM pipeline stage (null = no stage yet).
            'stage' => $this->stage_id === null ? null : $this->resource->loadMissing('stage')->stage?->summary(),
            'stage_changed_at' => $this->stage_changed_at?->toIso8601String(),
            'consent_state' => $this->consent_state->value,
            'opted_out_at' => $this->opted_out_at?->toIso8601String(),
            'marketing_opted_out' => $this->marketing_opted_out,
            'last_inbound_at' => $this->last_inbound_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // Growth Hub: where this contact came from. Null while Growth Hub is off for the workspace;
            // the chip only ({status, label}) for people without growth.view (Agents).
            'acquisition' => $this->acquisition(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function acquisition(): ?array
    {
        $context = app(TenantContext::class);
        $tenant = $context->tenantOrNull();

        if ($tenant === null) {
            return null;
        }
        $tier = GrowthAccess::tier($tenant);
        if ($tier === GrowthAccess::OFF) {
            return null;
        }

        // Details (sources, headline, landing page) need the full tier and growth.view; otherwise the chip only.
        $acquisition = Acquisition::present($this->resource, GrowthSettings::for($tenant), $tier === GrowthAccess::FULL && $context->can(Permission::GrowthView));

        // Starter (chip tier) shows the Meta ad chip only: no chip at all for other statuses.
        if ($tier === GrowthAccess::CHIP && $acquisition !== null && ! str_contains($acquisition['label'], 'Meta ad')) {
            return null;
        }

        return $acquisition;
    }
}
