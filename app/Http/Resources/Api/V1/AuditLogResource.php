<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
final class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->getAttribute('action'),
            'actor' => ['type' => $this->getAttribute('actor_type'), 'id' => $this->getAttribute('actor_id')],
            'entity' => ['type' => $this->getAttribute('entity_type'), 'id' => $this->getAttribute('entity_id')],
            'before' => $this->getAttribute('before'),
            'after' => $this->getAttribute('after'),
            'meta' => $this->getAttribute('meta'),
            'ip' => $this->getAttribute('ip'),
            'request_id' => $this->getAttribute('request_id'),
            'created_at' => $this->getAttribute('created_at')?->toIso8601String(),
        ];
    }
}
