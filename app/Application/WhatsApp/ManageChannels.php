<?php

declare(strict_types=1);

namespace App\Application\WhatsApp;

use App\Domain\Audit\AuditLogger;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $token = $this->credentials->tokenFor($waba);

        try {
            $this->sync->applyWaba($waba, $this->graph->getWaba($waba->waba_id, $token));

            $remote = collect($this->graph->listPhoneNumbers($waba->waba_id, $token))->keyBy(fn ($n) => (string) ($n['id'] ?? ''));
            foreach ($waba->phoneNumbers()->get() as $number) {
                if ($node = $remote->get($number->phone_number_id)) {
                    $this->sync->applyPhoneNumber($number, $node);
                }
            }
        } catch (MetaApiException $e) {
            throw WhatsappException::meta('Could not refresh from Meta: '.$e->getMessage(), $e->metaCode, $e->fbtraceId);
        }

        return $waba->refresh()->load('phoneNumbers');
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
        } catch (\Throwable $e) {
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
