<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Application\Notifications\WorkspaceMailer;
use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Templates\Enums\TemplateStatus;
use App\Domain\Templates\Jobs\SyncMessageTemplates;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;
use App\Notifications\TemplateRejectedNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            'template_category_update' => $this->category($template, $value),
            default => null,
        };

        return ProcessResult::Processed;
    }

    /**
     * Meta re-categorised the template (e.g. Utility → Marketing): its price changes with it. The old
     * category is kept to show the warning on the template and in campaigns, and Owners are told.
     *
     * @param  array<string, mixed>  $value
     */
    private function category(MessageTemplate $template, array $value): void
    {
        $new = isset($value['new_category']) ? strtoupper((string) $value['new_category']) : null;
        $old = $template->category !== null ? strtoupper((string) $template->category) : (isset($value['previous_category']) ? strtoupper((string) $value['previous_category']) : null);
        if ($new === null || $new === $old) {
            return;
        }
        $template->forceFill(['category' => $new, 'previous_category' => $old, 'category_changed_at' => now()])->save();

        try {
            $tenant = app(TenantContext::class)->tenantOrNull();
            if ($tenant === null) {
                return;
            }
            $owners = TenantMembership::query()->where('tenant_id', $tenant->id)->where('status', 'active')
                ->whereHas('role', fn ($q) => $q->where('key', SystemRole::Owner->value)->where('is_system', true))->get();
            $name = $template->name.' ('.$template->language.')';
            foreach ($owners as $owner) {
                app(InboxTools::class)->notify($owner, 'template_category', 'Meta re-categorised '.$name.' as '.ucfirst(strtolower($new)),
                    'It was '.ucfirst(strtolower((string) $old)).'. Meta now charges the '.strtolower($new).' rate for it, and campaigns using it are priced again.', '/templates');
            }
        } catch (Throwable $e) {
            Log::warning('Template category alert not sent', ['template_id' => $template->id, 'error' => $e->getMessage()]);
        }
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

        $newlyRejected = $status === TemplateStatus::REJECTED && $template->status !== TemplateStatus::REJECTED;
        $template->forceFill([
            'status' => $status,
            'rejected_reason' => $status === TemplateStatus::REJECTED && $reason !== null && $reason !== 'NONE' ? mb_substr($reason, 0, 64) : null,
            'last_synced_at' => now(),
        ])->save();

        $tenant = app(TenantContext::class)->tenantOrNull();
        if ($newlyRejected && $tenant !== null) {
            app(WorkspaceMailer::class)->toOwners($tenant, new TemplateRejectedNotification($tenant->name, (string) $template->name, (string) $template->language, $template->rejected_reason));
        }

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
