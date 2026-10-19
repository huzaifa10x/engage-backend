<?php

declare(strict_types=1);

namespace App\Application\WhatsApp;

use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Message;
use App\Domain\WhatsApp\Enums\MarketingMessagesStatus as Status;
use App\Domain\WhatsApp\Models\MmOnboardingEvent;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use Illuminate\Support\Facades\Log;

/**
 * Meta's Marketing Messages API (MM API), per WhatsApp Business Account:
 *  - sync():        reads Meta's onboarding state and mirrors it on the account (Meta is the source).
 *  - enroll():      asks the business to accept the MM Terms of Service (Meta's Intent API). A Tech
 *                   Provider can never accept for them: the request emails their portfolio admins,
 *                   who can also accept in WhatsApp Manager › Overview › Alerts.
 *  - termsSigned(): the account_update webhook MM_LITE_TERMS_SIGNED — the account is onboarded.
 *
 * The status only decides the route of campaign marketing messages (SendWhatsappMessage); it never
 * blocks sending. Every change is written to mm_onboarding_events.
 */
final class MarketingMessages
{
    /** Results of enroll(); never "onboarded" unless Meta already says so. */
    public const RESULT_ONBOARDED = 'onboarded';

    public const RESULT_REQUEST_SENT = 'request_sent';

    public const RESULT_AWAITING_CLIENT = 'awaiting_client';

    public const RESULT_NOT_ELIGIBLE = 'not_eligible';

    public const RESULT_DISABLED = 'disabled';

    /** messages.send_route */
    public const ROUTE_MM = 'mm_api';

    public const ROUTE_CLOUD = 'cloud_api';

    /** Sent to the MM API, delivered by Meta through the Cloud API (its CLOUD_API_FALLBACK policy). */
    public const ROUTE_MM_FALLBACK = 'mm_fallback';

    public function __construct(
        private readonly GraphClient $graph,
        private readonly WhatsappCredentials $credentials,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('engage.meta.marketing_messages.enabled', true);
    }

    /**
     * Meta's answer → our status (null = Meta told us nothing usable).
     *
     * @param  array<string, mixed>  $node  GET /<WABA_ID>?fields=marketing_messages_onboarding_status,owner_business_info{…}
     * @return array{0: ?Status, 1: ?string, 2: ?string} status, WABA status as returned, terms status as returned
     */
    public static function map(array $node): array
    {
        $waba = self::value($node['marketing_messages_onboarding_status'] ?? $node['marketing_messages_lite_api_status'] ?? null);
        $owner = is_array($node['owner_business_info'] ?? null) ? $node['owner_business_info'] : [];
        $terms = self::value($owner['marketing_messages_onboarding_status'] ?? null);

        $status = match (true) {
            $waba === 'ONBOARDED', $terms === 'TERM_OF_SERVICE_SIGNED', $terms === 'ONBOARDED' => Status::Onboarded,
            $waba !== null && (str_contains($waba, 'INELIGIBLE') || str_contains($waba, 'NOT_ELIGIBLE')) => Status::NotEligible,
            $terms === 'REQUEST_SENT' => Status::RequestSent,
            $waba === 'ELIGIBLE' => Status::Eligible,
            $terms === 'NOT_STARTED' || $waba === 'NOT_STARTED' => Status::NotStarted,
            default => null,
        };

        return [$status, $waba, $terms];
    }

    /** Re-reads the account's state from Meta and records any change. */
    public function sync(WabaAccount $waba, string $trigger = 'sync'): WabaAccount
    {
        $node = $this->graph->getMarketingMessagesStatus($waba->waba_id, $this->credentials->tokenFor($waba));
        [$status, $wabaStatus, $terms] = self::map($node);

        $waba->forceFill([
            'mm_meta_status' => $wabaStatus !== null ? mb_substr($wabaStatus, 0, 40) : $waba->getAttribute('mm_meta_status'),
            'mm_terms_status' => $terms !== null ? mb_substr($terms, 0, 40) : $waba->getAttribute('mm_terms_status'),
            'mm_status_synced_at' => now(),
        ]);
        $this->transition($waba, $this->reconcile($waba, $status), $trigger, $node);

        return $waba;
    }

    /**
     * Moves the account toward onboarded: checks Meta first, then — when it is eligible and nobody
     * asked recently — asks the business to accept the terms. Idempotent: an onboarded or
     * ineligible account is left as it is.
     */
    public function enroll(WabaAccount $waba, string $source): string
    {
        if (! self::enabled()) {
            return self::RESULT_DISABLED;
        }

        try {
            $this->sync($waba, $source === 'embedded_signup' ? 'embedded_signup' : 'backfill');
        } catch (MetaApiException $e) {
            Log::info('Marketing Messages status check failed; using the stored status.', ['waba_id' => $waba->waba_id] + $e->context());
        }

        $status = $waba->mm_status;
        if ($status === Status::Onboarded) {
            return self::RESULT_ONBOARDED;
        }
        if ($status === Status::NotEligible) {
            return self::RESULT_NOT_ELIGIBLE;
        }
        if ($status === Status::RequestSent && $this->askedRecently($waba)) {
            return self::RESULT_REQUEST_SENT;
        }

        $business = $waba->getAttribute('meta_business_id');
        if (! is_string($business) || $business === '') {
            $this->awaitClient($waba, $source, 'The business portfolio of this account is not known yet.');

            return self::RESULT_AWAITING_CLIENT;
        }

        try {
            $token = (string) (config('engage.meta.marketing_messages.partner_token') ?: $this->credentials->tokenFor($waba));
            $requestId = $this->graph->requestMarketingMessagesOnboarding($business, $token);
        } catch (MetaApiException $e) {
            // Meta answers a repeated request with "Your business has already sent this request…".
            if (str_contains(strtolower($e->getMessage()), 'already sent')) {
                $requestId = null;
            } else {
                $this->awaitClient($waba, $source, $e->getMessage());

                return self::RESULT_AWAITING_CLIENT;
            }
        }

        $waba->forceFill(['mm_requested_at' => now(), 'mm_request_error' => null, 'mm_consent_source' => $source]);
        $this->transition($waba, Status::RequestSent, 'request', array_filter(['request_id' => $requestId, 'source' => $source]));

        return self::RESULT_REQUEST_SENT;
    }

    /**
     * account_update MM_LITE_TERMS_SIGNED (or the legacy AD_ACCOUNT_LINKED): Meta onboarded the account.
     *
     * @param  array<string, mixed>  $payload
     */
    public function termsSigned(WabaAccount $waba, array $payload): void
    {
        $waba->forceFill([
            'mm_terms_status' => 'TERM_OF_SERVICE_SIGNED',
            'mm_request_error' => null,
            'mm_consent_source' => $waba->getAttribute('mm_consent_source') ?? 'meta',
            'mm_status_synced_at' => now(),
        ]);
        $this->transition($waba, Status::Onboarded, 'webhook', $payload);
    }

    /**
     * The send router: which API a queued message goes through. Campaign marketing templates of an
     * onboarded account → mm_api (POST /marketing_messages); everything else → cloud_api. Decided
     * when the message is sent, from the account's current status.
     */
    public static function routeFor(Message $message, ?WabaAccount $waba): string
    {
        $template = is_array($message->template) ? $message->template : [];
        $marketing = strtoupper((string) ($template['category'] ?? '')) === 'MARKETING';

        return self::enabled() && $waba !== null && $waba->usesMarketingMessagesApi()
            && $message->origin === MessageOrigin::Campaign && $message->type === 'template' && $marketing
            ? self::ROUTE_MM : self::ROUTE_CLOUD;
    }

    /** True when the account should be asked (again) by the scheduled run. */
    public function shouldAsk(WabaAccount $waba): bool
    {
        return self::enabled()
            && in_array($waba->mm_status, [Status::Eligible, Status::RequestSent], true)
            && ! $this->askedRecently($waba);
    }

    /**
     * Meta is the source of truth, with two exceptions that keep the status from flickering:
     * onboarded stays onboarded until Meta says the account is not eligible (the webhook can arrive
     * before the field updates), and a request we sent stays "sent" while it is recent.
     */
    private function reconcile(WabaAccount $waba, ?Status $fromMeta): Status
    {
        $current = $waba->mm_status ?? Status::NotStarted;
        if ($fromMeta === null) {
            return $current;
        }
        if ($current === Status::Onboarded && $fromMeta !== Status::NotEligible) {
            return Status::Onboarded;
        }
        if ($current === Status::RequestSent && in_array($fromMeta, [Status::Eligible, Status::NotStarted], true) && $this->askedRecently($waba)) {
            return Status::RequestSent;
        }

        return $fromMeta;
    }

    private function askedRecently(WabaAccount $waba): bool
    {
        $at = $waba->mm_requested_at;

        return $at !== null && $at->gt(now()->subDays(max(1, (int) config('engage.meta.marketing_messages.request_retry_days', 7))));
    }

    /** Meta refused the request: the business accepts in WhatsApp Manager instead. Not retried until the retry period passes. */
    private function awaitClient(WabaAccount $waba, string $source, string $reason): void
    {
        $waba->forceFill([
            'mm_requested_at' => now(),
            'mm_request_error' => mb_substr($reason, 0, 255),
            'mm_consent_source' => $source,
        ])->save();
    }

    /** @param array<string, mixed>|null $payload */
    private function transition(WabaAccount $waba, Status $to, string $trigger, ?array $payload): void
    {
        $from = $waba->mm_status ?? Status::NotStarted;
        if ($from !== $to) {
            $waba->forceFill(['mm_status' => $to]);
            if ($to === Status::Onboarded) {
                $waba->forceFill(['mm_onboarded_at' => now()]);
            }
        }
        $waba->save();

        if ($from !== $to) {
            MmOnboardingEvent::query()->create([
                'tenant_id' => $waba->tenant_id,
                'waba_account_id' => $waba->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'trigger' => $trigger,
                'raw_payload' => $payload ?: null,
            ]);
        }
    }

    private static function value(mixed $raw): ?string
    {
        if (is_array($raw)) {
            $raw = $raw['status'] ?? null;
        }

        return is_string($raw) && $raw !== '' ? strtoupper($raw) : null;
    }
}
