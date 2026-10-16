<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Domain\Messaging\Services\MessagingLimits;
use App\Domain\Webhooks\Jobs\RefreshPhoneNumber;
use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;
use App\Domain\WhatsApp\Models\QualityEvent;

/**
 * business_capability_update (limits per portfolio / WABA) and account_review_update. A new daily
 * messaging limit is applied to the account's numbers at once (so campaigns waiting for room go
 * on), then confirmed by re-reading the numbers from Graph.
 */
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
            $tier = MessagingLimits::tierName($change->value['max_daily_conversations_per_business'] ?? $change->value['max_daily_conversation_per_phone'] ?? null);
            foreach ($waba->phoneNumbers()->get() as $number) {
                if ($tier !== null && $number->messaging_limit_tier !== $tier) {
                    $previous = $number->messaging_limit_tier;
                    $number->forceFill(['messaging_limit_tier' => $tier])->save();
                    app(MessagingLimits::class)->limitChanged($number, $previous, $tier);
                }
                RefreshPhoneNumber::dispatch($number->id)->delay(now()->addSeconds(30));
            }
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
