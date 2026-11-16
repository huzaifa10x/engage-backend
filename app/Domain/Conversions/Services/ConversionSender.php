<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Services;

use App\Application\WhatsApp\WhatsappCredentials;
use App\Domain\Conversions\Models\AdClick;
use App\Domain\Conversions\Models\ConversionEvent;
use App\Domain\Conversions\Models\ConversionRule;
use App\Domain\WhatsApp\Exceptions\WhatsappException;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one WhatsApp account's queued conversion events to its Meta dataset
 * (POST /{DATASET_ID}/events, up to 1,000 per request) with the client's own token.
 *
 *  - Every event is checked first (known event name, event_time not in the future and under 7 days
 *    old, click ID present, currency and value for Purchase); one that fails is marked failed and
 *    never sent, which keeps Meta's error rate low.
 *  - Anything still unsent 6 days after it happened is marked expired and never sent.
 *  - Network errors, Meta's 5xx and its documented "try again" codes are retried with increasing
 *    gaps. Content errors are not retried: the event is marked failed with Meta's reason (a batch
 *    Meta refuses is split, so one bad event cannot fail the others).
 *  - A permission error marks the events skipped and flags the account for "Reconnect".
 *
 * Only the WhatsApp account ID and the ad click ID identify an event: no personal data is sent. The
 * token is never logged.
 */
final class ConversionSender
{
    public const BATCH = 1000;

    public const EXPIRE_AFTER_DAYS = 6;

    /** Minutes to wait before attempt 2, 3, … (then every 6 hours until it expires). */
    private const BACKOFF = [1, 5, 15, 60, 180];

    public function __construct(
        private readonly GraphClient $graph,
        private readonly WhatsappCredentials $credentials,
        private readonly ConversionReporting $reporting,
    ) {}

    /**
     * Sends the account's due events (or only $onlyEventId, at once: "Send test event").
     *
     * @return array{sent: int, failed: int, retry: int, skipped: int, expired: int}
     */
    public function send(string $wabaId, ?string $onlyEventId = null): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0, 'expired' => 0];

        $result['expired'] = ConversionEvent::query()->where('waba_id', $wabaId)->where('status', ConversionEvent::QUEUED)
            ->where('event_time', '<', now()->subDays(self::EXPIRE_AFTER_DAYS))
            ->update(['status' => ConversionEvent::EXPIRED, 'next_attempt_at' => null, 'updated_at' => now(),
                'error_reason' => 'Not sent within 6 days of the conversion. Meta only accepts events up to 7 days old.']);

        $due = ConversionEvent::query()->where('waba_id', $wabaId)->where('status', ConversionEvent::QUEUED)
            ->when($onlyEventId, fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now())))
            ->orderBy('event_time')->limit(self::BATCH)->get();
        if ($due->isEmpty()) {
            return $result;
        }

        $dataset = $this->reporting->datasetFor($wabaId);
        $waba = WabaAccount::query()->where('waba_id', $wabaId)->first();
        if ($dataset === null || $waba === null) {
            $result['skipped'] = $this->finish($due, ConversionEvent::SKIPPED, ConversionEvent::SKIP_REASONS['reporting_off']);

            return $result;
        }

        $clicks = AdClick::query()->whereIn('id', $due->pluck('ad_click_id')->filter()->all())->get()->keyBy('id');
        $ready = collect();
        foreach ($due as $event) {
            $problem = $this->problem($event, $clicks->get((string) $event->ad_click_id));
            if ($problem !== null) {
                $result['failed'] += $this->finish(collect([$event]), ConversionEvent::FAILED, $problem);
            } else {
                $ready->push($event);
            }
        }
        if ($ready->isEmpty()) {
            return $result;
        }

        try {
            $token = $this->credentials->tokenFor($waba);
        } catch (WhatsappException) {
            $result['skipped'] += $this->permissionLost($waba, $ready);

            return $result;
        }

        $this->deliver($dataset->dataset_id, $waba, $ready, $clicks, $token, $result);

        return $result;
    }

    /**
     * @param  Collection<int, ConversionEvent>  $events
     * @param  Collection<string, AdClick>  $clicks
     * @param  array{sent: int, failed: int, retry: int, skipped: int, expired: int}  $result
     */
    private function deliver(string $datasetId, WabaAccount $waba, Collection $events, Collection $clicks, string $token, array &$result): void
    {
        $payload = $events->map(fn (ConversionEvent $e) => $this->payload($e, $clicks->get((string) $e->ad_click_id), $waba->waba_id))->values()->all();
        try {
            $response = $this->graph->sendConversionEvents($datasetId, $payload, $token);
        } catch (MetaApiException $e) {
            if ($e->isAuthError()) {
                $result['skipped'] += $this->permissionLost($waba, $events, $e);
            } elseif ($e->isTransient()) {
                $result['retry'] += $this->retryLater($events, $e);
            } elseif ($events->count() > 1) {
                // Meta refuses the whole request for one bad event: send them one by one to find it.
                foreach ($events as $event) {
                    $this->deliver($datasetId, $waba, collect([$event]), $clicks, $token, $result);
                }
            } else {
                $result['failed'] += $this->finish($events, ConversionEvent::FAILED, 'Meta refused the event: '.mb_substr($e->getMessage(), 0, 400), self::metaError($e));
            }

            return;
        } catch (Throwable $e) {
            $result['retry'] += $this->retryLater($events, null);
            Log::warning('Conversion events not sent, will retry', ['waba_id' => $waba->waba_id, 'error' => $e->getMessage()]);

            return;
        }

        $answer = array_intersect_key($response, array_flip(['events_received', 'messages', 'fbtrace_id']));
        $result['sent'] += ConversionEvent::query()->whereIn('id', $events->pluck('id')->all())->update([
            'status' => ConversionEvent::SENT, 'sent_at' => now(), 'next_attempt_at' => null, 'error_reason' => null,
            'attempts' => DB::raw('attempts + 1'), 'meta_response' => json_encode($answer), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> one event as Meta expects it (no personal data) */
    private function payload(ConversionEvent $event, ?AdClick $click, string $wabaId): array
    {
        return array_filter([
            'event_name' => $event->event_name,
            'event_time' => $event->event_time->getTimestamp(),
            'event_id' => $event->id, // lets Meta drop a duplicate if a request is ever repeated
            'action_source' => 'business_messaging',
            'messaging_channel' => 'whatsapp',
            'user_data' => ['whatsapp_business_account_id' => $wabaId, 'ctwa_clid' => $click?->ctwa_clid],
            'custom_data' => $event->event_name === ConversionRule::PURCHASE ? ['currency' => strtoupper((string) $event->currency), 'value' => (float) $event->value] : null,
        ], fn ($v) => $v !== null);
    }

    /** Why this event must not be sent, or null when it is fine. */
    private function problem(ConversionEvent $event, ?AdClick $click): ?string
    {
        return match (true) {
            ! in_array($event->event_name, ConversionRule::EVENTS, true) => "Unknown event name \"{$event->event_name}\".",
            $event->event_time->gt(now()->addMinute()) => 'The conversion time is in the future.',
            $event->event_time->lt(now()->subDays(7)) => 'The conversion is more than 7 days old. Meta does not accept it.',
            $click === null || $click->ctwa_clid === null || $click->ctwa_clid === '' => ConversionEvent::SKIP_REASONS['no_click_id'],
            $event->event_name === ConversionRule::PURCHASE && (preg_match('/^[A-Z]{3}$/', strtoupper((string) $event->currency)) !== 1 || $event->value === null || (float) $event->value <= 0) => 'A purchase needs its currency and value.',
            default => null,
        };
    }

    /** @param Collection<int, ConversionEvent> $events */
    private function permissionLost(WabaAccount $waba, Collection $events, ?MetaApiException $e = null): int
    {
        // Channels shows "Reconnect" for the account (batch 2's permission flag) and the token is known to lack it.
        $waba->forceFill([
            'events_permission' => false, 'events_permission_checked_at' => now(),
            'permission_error_at' => now(), 'permission_error' => array_filter(['code' => $e?->metaCode, 'subcode' => $e?->metaSubcode, 'where' => 'conversions'], fn ($v) => $v !== null),
        ])->save();

        return $this->finish($events, ConversionEvent::SKIPPED, ConversionEvent::SKIP_REASONS['missing_permission'], $e !== null ? self::metaError($e) : null);
    }

    /** @param Collection<int, ConversionEvent> $events */
    private function retryLater(Collection $events, ?MetaApiException $e): int
    {
        foreach ($events as $event) {
            $attempt = $event->attempts + 1;
            $event->forceFill([
                'attempts' => $attempt,
                'next_attempt_at' => now()->addMinutes(self::BACKOFF[$attempt - 1] ?? 360),
                'error_reason' => 'Meta could not be reached; trying again'.($e !== null ? ' ('.mb_substr($e->getMessage(), 0, 200).')' : '').'.',
                'meta_response' => $e !== null ? self::metaError($e) : null,
            ])->save();
        }

        return $events->count();
    }

    /**
     * @param  Collection<int, ConversionEvent>  $events
     * @param  array<string, mixed>|null  $meta
     */
    private function finish(Collection $events, string $status, string $reason, ?array $meta = null): int
    {
        return ConversionEvent::query()->whereIn('id', $events->pluck('id')->all())->update([
            'status' => $status, 'error_reason' => mb_substr($reason, 0, 500), 'next_attempt_at' => null,
            'meta_response' => $meta !== null ? json_encode($meta) : null, 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function metaError(MetaApiException $e): array
    {
        return $e->context() + ['message' => mb_substr($e->getMessage(), 0, 400)];
    }
}
