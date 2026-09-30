<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;
use App\Domain\WhatsApp\Models\QualityEvent;

/** business_capability_update (limits per portfolio / WABA) and account_review_update. */
final class WabaCapabilityHandler implements WebhookHandler
{
    public function fields(): array
    {
        return ['business_capability_update', 'account_review_update'];
    }

    public function handle(WebhookChange $change): ProcessResult
    {
        $waba = $change->waba;
        if ($waba === null) {
            return ProcessResult::Ignored;
        }

        if ($change->field === 'account_review_update') {
            $old = $waba->getAttribute('account_review_status');
            $waba->forceFill(['account_review_status' => $change->value['decision'] ?? $old])->save();
        } else {
            $old = null;
            $waba->forceFill(['capabilities' => array_merge((array) $waba->getAttribute('capabilities'), $change->value)])->save();
        }

        QualityEvent::query()->create([
            'waba_account_id' => $waba->id,
            'event_type' => $change->field,
            'old_value' => is_string($old) ? $old : null,
            'new_value' => isset($change->value['decision']) ? (string) $change->value['decision'] : null,
            'payload' => $change->value,
            'occurred_at' => $change->occurredAt,
        ]);

        return ProcessResult::Processed;
    }
}
