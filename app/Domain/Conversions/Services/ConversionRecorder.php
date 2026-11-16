<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Services;

use App\Domain\Commerce\Models\CommerceOrder;
use App\Domain\Conversions\Models\AdClick;
use App\Domain\Conversions\Models\ConversionEvent;
use App\Domain\Conversions\Models\ConversionRule;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a conversion found by ConversionTriggers into one conversion event: queued to be sent, or
 * skipped with the reason (no click ID, test run, missing permission, reporting off). The database
 * keeps one Purchase per order and one LeadSubmitted per chat and rule, so a repeat (re-tagging, an
 * order edited, a flow run again) records nothing new. Nothing personal is stored or sent: the
 * event carries the WhatsApp account ID and the ad click ID only.
 */
final class ConversionRecorder
{
    public function __construct(private readonly AdClicks $clicks, private readonly ConversionReporting $reporting) {}

    public function record(string $triggerType, ?string $triggerRef, ?string $conversationId, ?string $contactId, Carbon $at, ?string $orderId = null, bool $isTest = false): ?ConversionEvent
    {
        $rule = ConversionRule::query()->where('trigger_type', $triggerType)->where('trigger_ref', $triggerRef)->where('enabled', true)->first();
        if ($rule === null) {
            return null;
        }

        $order = $orderId !== null ? CommerceOrder::query()->find($orderId) : null;
        if ($triggerType === ConversionRule::ORDER && ($order === null || ! $order->paid)) {
            return null;
        }

        // The chat it happened in, and the most recent ad click on it (up to that moment).
        $this->clicks->catchUp($conversationId, $contactId);
        $click = $conversationId !== null ? $this->clicks->latestForConversation($conversationId, $at)
            : ($contactId !== null ? $this->clicks->latestForContact($contactId, $at) : null);
        $conversationId ??= $click->conversation_id
            ?? ($contactId !== null ? Conversation::query()->where('contact_id', $contactId)->orderByDesc('last_message_at')->value('id') : null);
        if ($conversationId === null) {
            return null; // no chat with this person at all: nothing to report
        }
        $conversation = Conversation::query()->find($conversationId);
        $wabaId = $click->waba_id ?? ($conversation !== null
            ? DB::table('phone_numbers as p')->join('waba_accounts as w', 'w.id', '=', 'p.waba_account_id')->where('p.id', $conversation->phone_number_id)->value('w.waba_id')
            : null);

        $reason = $this->skipReason($isTest, $click, is_string($wabaId) ? $wabaId : null);
        $id = (string) Str::uuid7();
        $inserted = DB::table('conversion_events')->insertOrIgnore([
            'id' => $id,
            'tenant_id' => $rule->tenant_id,
            'conversation_id' => $conversationId,
            'contact_id' => $contactId ?? $conversation?->contact_id,
            'ad_click_id' => $click?->id,
            'rule_id' => $rule->id,
            'waba_id' => is_string($wabaId) ? $wabaId : null,
            'event_name' => $rule->event_name,
            'event_time' => $at,
            'value' => $rule->event_name === ConversionRule::PURCHASE ? $order?->total : null,
            'currency' => $rule->event_name === ConversionRule::PURCHASE ? $order?->currency : null,
            'order_id' => $order?->id,
            'status' => $reason === null ? ConversionEvent::QUEUED : ConversionEvent::SKIPPED,
            'error_reason' => $reason !== null ? ConversionEvent::SKIP_REASONS[$reason] : null,
            'next_attempt_at' => $reason === null ? now() : null,
            'is_test' => $isTest,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted > 0 ? ConversionEvent::query()->find($id) : null;
    }

    private function skipReason(bool $isTest, ?AdClick $click, ?string $wabaId): ?string
    {
        if ($isTest) {
            return 'test_run';
        }
        if ($wabaId === null || $this->reporting->datasetFor($wabaId) === null) {
            return 'reporting_off';
        }
        if ($click === null || $click->ctwa_clid === null || $click->ctwa_clid === '') {
            return 'no_click_id';
        }
        if (WabaAccount::query()->where('waba_id', $wabaId)->value('events_permission') === false) {
            return 'missing_permission';
        }

        return null;
    }
}
