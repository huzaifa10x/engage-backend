<?php

declare(strict_types=1);

namespace App\Domain\Developer\Services;

use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;

/**
 * The shapes customers see, in the public API and in webhook events alike. Kept separate from
 * the portal's own resources on purpose: these are a published contract and must not change
 * because an internal screen needed another field.
 */
final class PublicPayload
{
    /** @return array<string, mixed> */
    public static function contact(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'phone' => $contact->wa_id !== null ? '+'.$contact->wa_id : null,
            'name' => $contact->name ?? $contact->profile_name,
            'email' => $contact->email,
            'tags' => $contact->tags,
            'attributes' => (object) ($contact->custom_fields ?? []),
            'consent' => $contact->consent_state->value,
            'created_at' => $contact->getAttribute('created_at')?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function message(Message $message): array
    {
        $contact = $message->contact;

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'phone_number_id' => $message->phone_number_id,
            'direction' => $message->direction,
            'type' => $message->type,
            'status' => $message->status->value,
            'text' => $message->body, // the caption, for media messages
            'template' => $message->type === 'template' ? ['name' => $message->template['name'] ?? null, 'language' => $message->template['language'] ?? null] : null,
            'media' => $message->media_id !== null ? ['id' => $message->media_id, 'mime_type' => $message->media?->mime_type, 'filename' => $message->media?->filename] : null,
            'whatsapp_message_id' => $message->wamid,
            'origin' => $message->origin->value,
            'contact' => $contact !== null ? ['id' => $contact->id, 'phone' => $contact->wa_id !== null ? '+'.$contact->wa_id : null, 'name' => $contact->name ?? $contact->profile_name] : null,
            'error' => $message->error_code !== null ? ['code' => $message->error_code, 'message' => $message->error_title] : null,
            'created_at' => ($message->occurred_at ?? $message->created_at)?->toIso8601String(), // when the message happened
        ];
    }

    /** @return array<string, mixed> */
    public static function conversation(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'phone_number_id' => $conversation->phone_number_id,
            'status' => $conversation->status->value,
            'assigned_to' => $conversation->assigned_membership_id,
            'unread_count' => $conversation->unread_count,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'contact' => $conversation->contact !== null ? self::contact($conversation->contact) : null,
        ];
    }
}
