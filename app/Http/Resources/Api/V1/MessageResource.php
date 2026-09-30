<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Messaging\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
final class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $media = $this->relationLoaded('media') ? $this->media : null;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'direction' => $this->direction,
            'origin' => $this->origin->value,
            'type' => $this->type,
            'status' => $this->status->value,
            'body' => $this->body,
            'content' => (object) ($this->content ?? []),
            'template' => $this->template,
            'media' => $media === null ? null : [
                'id' => $media->id,
                'status' => $media->status,
                'mime_type' => $media->mime_type,
                'filename' => $media->filename,
                'file_size' => $media->file_size,
                'url' => $media->isReady() ? url("/api/v1/media/{$media->id}") : null,
            ],
            'wamid' => $this->wamid,
            'reply_to_wamid' => $this->context_wamid,
            'error' => $this->error_code ? ['code' => $this->error_code, 'title' => $this->error_title] : null,
            'pricing' => $this->getAttribute('pricing'),
            'sent_by_membership_id' => $this->sent_by_membership_id,
            'timestamp' => ($this->meta_timestamp ?? $this->created_at)?->toIso8601String(),
            'sent_at' => $this->getAttribute('sent_at')?->toIso8601String(),
            'delivered_at' => $this->getAttribute('delivered_at')?->toIso8601String(),
            'read_at' => $this->getAttribute('read_at')?->toIso8601String(),
            'edited_at' => $this->getAttribute('edited_at')?->toIso8601String(),
            'revoked_at' => $this->getAttribute('revoked_at')?->toIso8601String(),
        ];
    }
}
