<?php

declare(strict_types=1);

namespace App\Application\WhatsApp;

use App\Domain\Audit\AuditLogger;
use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Conversions\Services\ConversionReporting;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Exceptions\WhatsappException;
use App\Domain\WhatsApp\Models\MetaAccessToken;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use App\Infrastructure\Secrets\SecretStore;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ManageChannels
{
    public function __construct(
        private readonly GraphClient $graph,
        private readonly WhatsappCredentials $credentials,
        private readonly PhoneNumberSync $sync,
        private readonly SecretStore $secrets,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** Refresh WABA + known numbers from Meta. New numbers are NOT auto-added (plan slots). */
    public function refresh(WabaAccount $waba): WabaAccount
    {
        try {
            $token = $this->credentials->tokenFor($waba);
        } catch (WhatsappException $e) {
            app(RefreshDiagnostics::class)->logWithoutToken($waba, $e->getMessage(), 'channels_refresh');
            throw $e;
        }

        try {
            $this->sync->applyWaba($waba, $this->graph->getWaba($waba->waba_id, $token));

            // Whether our app still receives this account's webhooks (Channels offers "Reconnect webhooks" if not).
            try {
                $waba->forceFill(['is_subscribed_to_webhooks' => in_array($this->graph->appId(), array_column($this->graph->listSubscribedApps($waba->waba_id, $token), 'id'), true)])->save();
            } catch (MetaApiException) {
                // not decisive for the refresh; the flag keeps its last known value
            }

            $remote = collect($this->graph->listPhoneNumbers($waba->waba_id, $token))->keyBy(fn ($n) => (string) ($n['id'] ?? ''));
            foreach ($waba->phoneNumbers()->get() as $number) {
                if ($node = $remote->get($number->phone_number_id)) {
                    $this->sync->applyPhoneNumber($number, $node);
                }
            }
        } catch (MetaApiException $e) {
            app(RefreshDiagnostics::class)->log($waba, $e, $token, 'channels_refresh');
            if (WhatsappException::isPermissionError($e->metaCode)) {
                $waba->forceFill(['permission_error_at' => now(), 'permission_error' => array_filter(['code' => $e->metaCode, 'subcode' => $e->metaSubcode], fn ($v) => $v !== null)])->save();
            }
            throw WhatsappException::meta('Could not refresh from Meta: '.$e->getMessage(), $e->metaCode, $e->fbtraceId, $e->metaSubcode);
        }

        // Conversions to Meta (flagged workspaces only): re-check whatsapp_business_manage_events on the token.
        try {
            $tenant = $this->context->tenantOrNull();
            if ($tenant !== null && ConversionsAccess::allowed($tenant)) {
                app(ConversionReporting::class)->checkPermission($waba, $token);
            }
        } catch (Throwable $e) {
            Log::info('Conversion reporting permission not checked.', ['waba_id' => $waba->waba_id, 'error' => $e->getMessage()]);
        }

        // Marketing Messages status too; a refusal here must not spoil the refresh.
        if (MarketingMessages::enabled()) {
            try {
                app(MarketingMessages::class)->sync($waba);
            } catch (MetaApiException $e) {
                Log::info('Marketing Messages status not refreshed.', ['waba_id' => $waba->waba_id] + $e->context());
            }
        }

        return $waba->refresh()->load('phoneNumbers');
    }

    /**
     * "Reconnect webhooks" on Channels: subscribe our app to the account's webhooks again
     * (POST /<WABA_ID>/subscribed_apps) and check Meta actually lists it. Without the subscription
     * no message, status or quality update reaches engageX.
     */
    public function reconnectWebhooks(WabaAccount $waba): WabaAccount
    {
        $token = $this->credentials->tokenFor($waba);
        try {
            $this->graph->subscribeApp($waba->waba_id, $token);
            $subscribed = in_array($this->graph->appId(), array_column($this->graph->listSubscribedApps($waba->waba_id, $token), 'id'), true);
        } catch (MetaApiException $e) {
            $waba->forceFill(['is_subscribed_to_webhooks' => false])->save();
            $this->audit->record('whatsapp.webhooks_reconnect_failed', $waba, meta: $e->context());
            throw WhatsappException::meta('Meta did not accept the webhook subscription. Try again in a few minutes; if it keeps failing, reconnect WhatsApp.', $e->metaCode, $e->fbtraceId, $e->metaSubcode);
        }
        $waba->forceFill(['is_subscribed_to_webhooks' => $subscribed])->save();
        $this->audit->record($subscribed ? 'whatsapp.webhooks_reconnected' : 'whatsapp.webhooks_reconnect_failed', $waba);
        if (! $subscribed) {
            throw WhatsappException::meta('Meta accepted the request but doesn’t list engageX on this account yet. Try again in a few minutes.', null, null);
        }

        return $waba->refresh()->load('phoneNumbers');
    }

    /**
     * "Register now" on Channels: Meta requires a new number to be registered on the Cloud API
     * within 14 days of Embedded Signup. Uses the number's stored two-step PIN (created and stored
     * encrypted if there is none yet).
     */
    public function register(PhoneNumber $number): PhoneNumber
    {
        if ($number->isCoexistence()) {
            throw new WhatsappException('Numbers that stay on the WhatsApp Business app are registered by Meta, not here.', ErrorCode::Conflict, 409);
        }
        $waba = $number->wabaAccount()->firstOrFail();
        $token = $this->credentials->tokenFor($waba);
        if ($number->pin_secret_id === null) {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $number->forceFill(['pin_secret_id' => $this->secrets->put('whatsapp.registration_pin', $pin, $number->tenant_id)])->save();
        }
        try {
            $this->graph->registerPhoneNumber($number->phone_number_id, $this->secrets->get((string) $number->pin_secret_id), $token);
        } catch (MetaApiException $e) {
            $this->audit->record('whatsapp.number_register_failed', $number, meta: $e->context());
            throw WhatsappException::meta('Meta did not register the number: '.$e->getMessage(), $e->metaCode, $e->fbtraceId, $e->metaSubcode);
        }
        $number->forceFill(['registered_at' => now()])->save();
        $this->audit->record('whatsapp.number_registered', $number);
        try {
            $this->sync->applyPhoneNumber($number, $this->graph->getPhoneNumber($number->phone_number_id, $token));
        } catch (MetaApiException) {
            // registered; the details catch up on the next refresh
        }

        return $number->refresh();
    }

    /** Refresh one number (quality / limit webhooks trigger this). */
    public function refreshNumber(PhoneNumber $number): void
    {
        $waba = $number->wabaAccount()->firstOrFail();
        $this->sync->applyPhoneNumber($number, $this->graph->getPhoneNumber($number->phone_number_id, $this->credentials->tokenFor($waba)));
    }

    /**
     * Stop using a WABA: unsubscribe webhooks (best effort — the token may already be dead),
     * mark everything disconnected and crypto-shred the token. Coexistence numbers stay on the
     * WhatsApp Business app; Meta forbids deregistering them from the API side.
     */
    public function disconnect(WabaAccount $waba, string $reason): void
    {
        // Stays true when the unsubscribe call fails: our app is then still subscribed at Meta, and
        // a later reconnect must not mistake that for another environment owning the account.
        $stillSubscribed = false;
        try {
            $this->graph->unsubscribeApp($waba->waba_id, $this->credentials->tokenFor($waba));
        } catch (Throwable $e) {
            $stillSubscribed = (bool) $waba->is_subscribed_to_webhooks;
            Log::warning('Unsubscribe during disconnect failed; continuing.', ['waba_id' => $waba->waba_id, 'error' => $e->getMessage()]);
        }

        DB::transaction(function () use ($waba, $reason, $stillSubscribed) {
            $numbers = $waba->phoneNumbers()->get();
            foreach ($numbers as $number) {
                $number->forceFill([
                    'status' => PhoneNumberStatus::Disconnected,
                    'coexistence_status' => $number->isCoexistence() ? CoexistenceStatus::Offboarded : $number->coexistence_status,
                    'disconnect_reason' => 'manual',
                    'disconnected_at' => now(),
                ])->save();
            }

            $this->revokeToken($waba);
            $waba->forceFill(['status' => WabaStatus::Disconnected, 'disconnected_at' => now(), 'disconnect_reason' => 'manual', 'is_subscribed_to_webhooks' => $stillSubscribed])->save();

            $this->audit->record('whatsapp.disconnected', $waba, meta: [
                'reason' => $reason,
                'numbers' => $numbers->pluck('phone_number_id')->all(),
            ]);
        });
    }

    public function revokeToken(WabaAccount $waba): void
    {
        $token = $this->context->bypass(fn () => MetaAccessToken::query()->find($waba->access_token_id));
        if ($token instanceof MetaAccessToken && $token->revoked_at === null) {
            $token->forceFill(['revoked_at' => now()])->save();
            $this->secrets->destroy($token->secret_id);
        }
    }
}
