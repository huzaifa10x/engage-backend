<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Templates\Models\MessageTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

/** @mixin MessageTemplate */
final class TemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        try {
            return $this->full();
        } catch (Throwable $e) {
            // One unreadable template must never take the whole Templates page down: show it, not sendable.
            report($e);

            return [
                'id' => $this->id,
                'waba_account_id' => $this->waba_account_id,
                'meta_template_id' => $this->meta_template_id,
                'name' => (string) $this->name,
                'language' => (string) $this->language,
                'category' => $this->category,
                'status' => $this->status,
                'sendable' => false,
                'quality_score' => null,
                'rejected_reason' => null,
                'parameter_format' => 'POSITIONAL',
                'components' => [],
                'variables' => ['header' => [], 'header_format' => null, 'body' => [], 'buttons' => []],
                'last_synced_at' => null,
                'created_at' => null,
                'updated_at' => null,
            ];
        }
    }

    /** @return array<string, mixed> */
    private function full(): array
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
            'components' => array_values(array_filter((array) ($this->components ?? []), 'is_array')),
            'variables' => $this->variables(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
