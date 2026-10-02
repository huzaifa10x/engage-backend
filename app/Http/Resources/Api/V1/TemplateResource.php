<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Templates\Models\MessageTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MessageTemplate */
final class TemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'waba_account_id' => $this->waba_account_id,
            'meta_template_id' => $this->meta_template_id,
            'name' => $this->name,
            'language' => $this->language,
            'category' => $this->category,
            'status' => $this->status,
            'sendable' => $this->isSendable(),
            'quality_score' => $this->quality_score,
            'rejected_reason' => $this->rejected_reason,
            'parameter_format' => $this->parameter_format,
            'components' => $this->components ?? [],
            'variables' => $this->variables(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
