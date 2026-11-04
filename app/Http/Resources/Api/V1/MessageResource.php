<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Access\Permission;
use App\Domain\Growth\Website\Reference;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\TenantContext;
use App\Support\Meta\MetaErrorCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
final class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $media = $this->relationLoaded('media') ? $this->media : null;
        // Growth Hub: a customer's message is shown without its website code ("… (Ref: XG-…)").
        // Owners and Admins can show the raw text (audit); agents never see the code.
        $clean = $this->direction === Message::INBOUND ? Reference::clean($this->body) : $this->body;
        $hasCode = $clean !== $this->body;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'direction' => $this->direction,
            'origin' => $this->origin->value,
            'type' => $this->type,
            'status' => $this->status->value,
            'body' => $clean,
            'raw_body' => $hasCode && app(TenantContext::class)->can(Permission::GrowthManage) ? $this->body : null,
            'content' => (object) ($hasCode ? ['body' => $clean] + (array) $this->content : ($this->content ?? [])),
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
            'error' => $this->error_code ? [
                'code' => $this->error_code,
                'title' => $this->error_title,
                'detail' => $this->errorDetail(),
                'hint' => MetaErrorCatalog::hint($this->error_code),
            ] : null,
            'pricing' => $this->getAttribute('pricing'),
            'sent_by_membership_id' => $this->sent_by_membership_id,
            'timestamp' => ($this->occurred_at ?? $this->meta_timestamp ?? $this->created_at)?->toIso8601String(),
            'sent_at' => $this->getAttribute('sent_at')?->toIso8601String(),
            'delivered_at' => $this->getAttribute('delivered_at')?->toIso8601String(),
            'read_at' => $this->getAttribute('read_at')?->toIso8601String(),
            'edited_at' => $this->getAttribute('edited_at')?->toIso8601String(),
            'revoked_at' => $this->getAttribute('revoked_at')?->toIso8601String(),
            // Content removed by the workspace's retention policy (the delivery record remains).
            'redacted' => $this->getAttribute('redacted_at') !== null,
        ];
    }

    /** Meta's longer explanation (error_data.details / error_user_msg), when it adds something. */
    private function errorDetail(): ?string
    {
        $error = (array) ($this->getAttribute('error_details') ?? []);
        $detail = $error['error_data']['details'] ?? $error['error_user_msg'] ?? $error['message'] ?? null;

        return is_string($detail) && $detail !== '' && $detail !== $this->error_title ? mb_substr($detail, 0, 500) : null;
    }
}
