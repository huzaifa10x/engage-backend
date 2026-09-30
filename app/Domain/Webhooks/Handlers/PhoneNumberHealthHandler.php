<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Domain\Webhooks\Jobs\RefreshPhoneNumber;
use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;
use App\Domain\WhatsApp\Models\QualityEvent;

/**
 * phone_number_quality_update (limit / throughput / flagged), phone_number_name_update (display
 * name review) and account_alerts. The payload carries the new limit but not the quality rating,
 * so a debounced refresh re-reads the number from Graph.
 */
final class PhoneNumberHealthHandler implements WebhookHandler
{
    public function fields(): array
    {
        return ['phone_number_quality_update', 'phone_number_name_update', 'account_alerts'];
    }

    public function handle(WebhookChange $change): ProcessResult
    {
        if ($change->waba === null) {
            return ProcessResult::Ignored;
        }

        $v = $change->value;
        $number = $change->numberByDisplay(isset($v['display_phone_number']) ? (string) $v['display_phone_number'] : null);

        [$type, $old, $new] = match ($change->field) {
            'phone_number_quality_update' => ['quality.'.mb_strtolower((string) ($v['event'] ?? 'update')), $number?->messaging_limit_tier, $v['current_limit'] ?? null],
            'phone_number_name_update' => ['name.'.mb_strtolower((string) ($v['decision'] ?? 'update')), $number?->verified_name, $v['requested_verified_name'] ?? null],
            default => ['alert.'.mb_strtolower((string) ($v['alert_type'] ?? $v['entity_type'] ?? 'account')), null, $v['alert_severity'] ?? null],
        };

        if ($number !== null && $change->field === 'phone_number_quality_update' && is_string($v['current_limit'] ?? null)) {
            $number->forceFill(['messaging_limit_tier' => $v['current_limit']])->save();
        }

        if ($number !== null && $change->field === 'phone_number_name_update') {
            $decision = (string) ($v['decision'] ?? '');
            $number->forceFill(array_filter([
                'name_status' => $decision !== '' ? $decision : null,
                'verified_name' => $decision === 'APPROVED' ? ($v['requested_verified_name'] ?? null) : null,
            ]))->save();
        }

        QualityEvent::query()->create([
            'waba_account_id' => $change->waba->id,
            'phone_number_id' => $number?->id,
            'event_type' => mb_substr($type, 0, 48),
            'old_value' => is_scalar($old) ? mb_substr((string) $old, 0, 64) : null,
            'new_value' => is_scalar($new) ? mb_substr((string) $new, 0, 64) : null,
            'payload' => $v,
            'occurred_at' => $change->occurredAt,
        ]);

        if ($number !== null && $change->field === 'phone_number_quality_update') {
            RefreshPhoneNumber::dispatch($number->id)->delay(now()->addSeconds(30));
        }

        return ProcessResult::Processed;
    }
}
