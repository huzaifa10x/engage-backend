<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Messaging\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Conversation */
final class ConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone_number_id' => $this->phone_number_id,
            'status' => $this->status->value,
            'snoozed_until' => ($until = $this->getAttribute('snoozed_until')) !== null && $until->isFuture() ? $until->toIso8601String() : null,
            'auto_reply_enabled' => (bool) ($this->getAttribute('auto_reply_enabled') ?? true),
            'assigned_membership_id' => $this->assigned_membership_id,
            'unread_count' => $this->unread_count,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_message_preview' => $this->getAttribute('last_message_preview'),
            'last_message_direction' => $this->getAttribute('last_message_direction'),
            'window' => [
                'open' => $this->isWindowOpen(),
                'expires_at' => $this->window_expires_at?->toIso8601String(),
            ],
            'contact' => ContactResource::make($this->whenLoaded('contact')),
            'phone_number' => $this->whenLoaded('phoneNumber', fn () => [
                'id' => $this->phoneNumber?->id,
                'display_phone_number' => $this->phoneNumber?->display_phone_number,
                'verified_name' => $this->phoneNumber?->verified_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
