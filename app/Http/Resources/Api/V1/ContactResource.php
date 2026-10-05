<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Messaging\Models\Contact;
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
            'consent_state' => $this->consent_state->value,
            'opted_out_at' => $this->opted_out_at?->toIso8601String(),
            'marketing_opted_out' => $this->marketing_opted_out,
            'last_inbound_at' => $this->last_inbound_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
