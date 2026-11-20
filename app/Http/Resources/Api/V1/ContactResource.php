<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Access\Permission;
use App\Domain\Access\PhoneVisibility;
use App\Domain\Crm\Services\ContactRecord;
use App\Domain\Growth\GrowthAccess;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Growth\Services\Acquisition;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Facades\DB;

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
            // Masked for teammates who may not see phone numbers (item 13).
            'phone' => app(PhoneVisibility::class)->show($this->wa_id),
            'wa_id' => app(PhoneVisibility::class)->canSee() ? $this->wa_id : null,
            'phone_hidden' => $this->wa_id !== null && ! app(PhoneVisibility::class)->canSee(),
            'bsuid' => $this->bsuid,
            'email' => $this->email,
            'attributes' => (object) ($this->custom_fields ?? []),
            'tags' => $this->tags,
            'source' => $this->source,
            // The link, QR code or website button that brought the contact in ("Shop window").
            'source_detail' => $this->source_detail,
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
            // The Subscribe / Unsubscribe question is waiting for an answer since then.
            'consent_requested_at' => $this->consent_requested_at?->toIso8601String(),
            'marketing_opted_out' => $this->marketing_opted_out,
            'last_inbound_at' => $this->last_inbound_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // Growth Hub: where this contact came from. Null while Growth Hub is off for the workspace;
            // the chip only ({status, label}) for people without growth.view (Agents).
            'acquisition' => $this->acquisition(),
            // The contact record (3.5)
            'company' => $this->company,
            'job_title' => $this->job_title,
            'city' => $this->city,
            'country' => $this->country,
            'language' => $this->language,
            'owner' => $this->owner_membership_id === null ? null : ['id' => $this->owner_membership_id, 'name' => self::memberNames($request)[$this->owner_membership_id] ?? 'Former teammate'],
            'lifecycle' => $lifecycle = self::record($request)->lifecycle($this->resource),
            'lead_status' => self::record($request)->leadStatus($this->resource, $lifecycle['key']),
            'contact_permission' => $this->contact_permission === null ? null : $this->contact_permission + ['via_label' => ContactRecord::PERMISSION_VIA[$this->contact_permission['via'] ?? 'other'] ?? 'Other'],
        ];
    }

    /**
     * Collections load what lifecycle and lead status need in a few queries instead of per row.
     *
     * @param  mixed  $resource
     */
    public static function collection($resource)
    {
        $items = $resource instanceof AbstractPaginator ? $resource->getCollection() : collect($resource);
        self::record(request())->preload($items->filter(fn ($c) => $c instanceof Contact));

        return parent::collection($resource);
    }

    /** One ContactRecord per request, so a page of contacts shares the facts loaded for it. */
    private static function record(Request $request): ContactRecord
    {
        if (! $request->attributes->has('crm.contact_record')) {
            $request->attributes->set('crm.contact_record', new ContactRecord);
        }

        return $request->attributes->get('crm.contact_record');
    }

    /** @return array<string, string> */
    private static function memberNames(Request $request): array
    {
        if (! $request->attributes->has('inbox.member_names')) {
            $request->attributes->set('inbox.member_names', DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')
                ->pluck('users.name', 'tenant_memberships.id')->all());
        }

        return (array) $request->attributes->get('inbox.member_names');
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

        // Full tier: everyone who sees the contact gets the Source panel line (no codes or click IDs);
        // the evidence-backed details also need growth.view. Starter: the chip only.
        $full = $tier === GrowthAccess::FULL;
        $acquisition = Acquisition::present($this->resource, GrowthSettings::for($tenant), $full && $context->can(Permission::GrowthView), $full);

        // Starter (chip tier) shows the Meta ad chip only: no chip at all for other statuses.
        if ($tier === GrowthAccess::CHIP && $acquisition !== null && ! str_contains($acquisition['label'], 'Meta ')) {
            return null;
        }

        return $acquisition;
    }
}
