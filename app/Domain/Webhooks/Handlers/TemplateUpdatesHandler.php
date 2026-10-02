<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Domain\Templates\Enums\TemplateStatus;
use App\Domain\Templates\Jobs\SyncMessageTemplates;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;

/**
 * Real-time template changes from Meta: review decisions and pauses (status), quality rating,
 * category changes and edits made in WhatsApp Manager. Status / quality / category are applied
 * directly; anything we cannot map exactly (new template, edited content) triggers a full sync
 * of that WABA, so the mirror always converges on what Meta has.
 */
final class TemplateUpdatesHandler implements WebhookHandler
{
    public function fields(): array
    {
        return ['message_template_status_update', 'message_template_quality_update', 'template_category_update', 'message_template_components_update'];
    }

    public function handle(WebhookChange $change): ProcessResult
    {
        $waba = $change->waba;
        if ($waba === null) {
            return ProcessResult::Ignored;
        }

        $value = $change->value;
        $template = $this->find($waba->id, $value);

        if ($template === null || $change->field === 'message_template_components_update') {
            SyncMessageTemplates::dispatch($waba->id)->afterCommit();

            return ProcessResult::Processed;
        }

        match ($change->field) {
            'message_template_status_update' => $this->status($template, $value),
            'message_template_quality_update' => $template->forceFill([
                'quality_score' => isset($value['new_quality_score']) ? strtoupper((string) $value['new_quality_score']) : $template->quality_score,
            ])->save(),
            'template_category_update' => isset($value['new_category'])
                ? $template->forceFill(['category' => strtoupper((string) $value['new_category'])])->save()
                : null,
            default => null,
        };

        return ProcessResult::Processed;
    }

    /** @param array<string, mixed> $value */
    private function status(MessageTemplate $template, array $value): void
    {
        $event = strtoupper((string) ($value['event'] ?? ''));
        if ($event === '') {
            return;
        }

        $status = TemplateStatus::normalize($event);
        $reason = isset($value['reason']) ? strtoupper((string) $value['reason']) : null;

        $template->forceFill([
            'status' => $status,
            'rejected_reason' => $status === TemplateStatus::REJECTED && $reason !== null && $reason !== 'NONE' ? mb_substr($reason, 0, 64) : null,
            'last_synced_at' => now(),
        ])->save();

        if ($status === TemplateStatus::DELETED) {
            $template->delete();
        } elseif ($template->trashed()) {
            $template->restore();
        }
    }

    /** @param array<string, mixed> $value */
    private function find(string $wabaAccountId, array $value): ?MessageTemplate
    {
        $query = MessageTemplate::query()->withTrashed()->where('waba_account_id', $wabaAccountId);

        if (isset($value['message_template_id'])) {
            $byId = (clone $query)->where('meta_template_id', (string) $value['message_template_id'])->first();
            if ($byId !== null) {
                return $byId;
            }
        }

        if (isset($value['message_template_name'], $value['message_template_language'])) {
            return $query->where('name', (string) $value['message_template_name'])
                ->where('language', (string) $value['message_template_language'])->first();
        }

        return null;
    }
}
