<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Messaging\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Growth Hub lead: the CRM contact (with its acquisition, as ContactResource shows it to this
 * member) on one WhatsApp number, with the conversation it lives in.
 *
 * @mixin Conversation
 */
final class LeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $assignee = $this->assignee;

        return [
            'id' => $this->id,
            'conversation_id' => $this->id,
            'status' => $this->status->value,
            'contact' => ContactResource::make($this->contact),
            'phone_number' => $this->phoneNumber ? [
                'id' => $this->phoneNumber->id,
                'display_phone_number' => $this->phoneNumber->display_phone_number,
                'verified_name' => $this->phoneNumber->verified_name,
            ] : null,
            'assigned' => $assignee ? ['id' => $assignee->id, 'name' => $assignee->user?->name] : null,
            'first_contact_at' => $this->contact?->created_at?->toIso8601String(),
            'last_activity_at' => $this->last_message_at?->toIso8601String(),
        ];
    }
}
