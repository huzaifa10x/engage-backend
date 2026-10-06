<?php

declare(strict_types=1);

namespace App\Application\WhatsApp;

use App\Domain\Audit\AuditLogger;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Enums\SignupEvent;
use App\Domain\WhatsApp\Enums\SignupStatus;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Exceptions\WhatsappException;
use App\Domain\WhatsApp\Jobs\ProvisionWhatsappAccount;
use App\Domain\WhatsApp\Models\EmbeddedSignupAttempt;
use App\Domain\WhatsApp\Models\MetaAccessToken;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use App\Infrastructure\Secrets\SecretStore;
use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Embedded Signup v4, Tech Provider path:
 *   start()    → attempt row + the FB.login() launch config for the client
 *   complete() → SYNCHRONOUS code exchange (the code lives 30 seconds), token validation,
 *                WABA / number upsert, then ProvisionWhatsappAccount runs the Graph calls
 *                (subscribe, register, fetch, coexistence sync) with retries
 *   cancel()   → records the abandoned screen or the user-reported error
 */
final class EmbeddedSignup
{
    private const ATTEMPT_TTL_MINUTES = 60;

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly SecretStore $secrets,
        private readonly AuditLogger $audit,
        private readonly GraphClient $graph,
    ) {}

    /** @return array{attempt: EmbeddedSignupAttempt, numbers: array{used: int, limit: ?int}, launch: array<string, mixed>} */
    public function start(bool $coexistence): array
    {
        $tenant = $this->context->tenant();
        $this->assertConfigured();

        if ($coexistence) {
            if (! config('engage.meta.coexistence_enabled') || ! $this->entitlements->allows($tenant, FeatureKey::Coexistence)) {
                throw WhatsappException::coexistenceUnavailable();
            }
        }

        // Not a hard stop here: re-running signup for an already connected number (token refresh)
        // needs no new slot. The client shows a warning; complete() enforces the real limit.
        $limit = $this->entitlements->for($tenant)->get(FeatureKey::WhatsappNumbers)->limit;
        $used = (int) $this->entitlements->usage($tenant, FeatureKey::WhatsappNumbers);

        $attempt = EmbeddedSignupAttempt::query()->create([
            'initiated_by_user_id' => $this->context->userId(),
            'flow' => $coexistence ? 'coexistence' : 'standard',
            'status' => SignupStatus::Started,
        ]);

        $extras = ['setup' => new \stdClass];
        if ($coexistence) {
            $extras['featureType'] = 'whatsapp_business_app_onboarding';
            $extras['sessionInfoVersion'] = '3';
        }

        // Coexistence has its own Facebook Login for Business configuration (falls back to the standard one).
        $configId = (string) (($coexistence ? config('engage.meta.coexistence_config_id') : null) ?: config('engage.meta.embedded_signup_config_id'));

        return ['attempt' => $attempt, 'numbers' => ['used' => $used, 'limit' => $limit], 'launch' => [
            'app_id' => (string) config('engage.meta.app_id'),
            'config_id' => $configId,
            'graph_version' => $this->graph->version(),
            'login_options' => [
                'config_id' => $configId,
                'response_type' => 'code',
                'override_default_response_type' => true,
                'extras' => $extras,
            ],
        ]];
    }

    /**
     * @param  array{code: string, event: string, waba_id: string, phone_number_id?: ?string, business_id?: ?string, meta_user_id?: ?string, session_id?: ?string}  $data
     */
    public function complete(EmbeddedSignupAttempt $attempt, array $data): EmbeddedSignupAttempt
    {
        $tenant = $this->context->tenant();
        $event = SignupEvent::tryFrom($data['event']);

        if ($attempt->status !== SignupStatus::Started || $attempt->created_at?->lt(now()->subMinutes(self::ATTEMPT_TTL_MINUTES))) {
            throw WhatsappException::signupInvalid();
        }

        // Atomic claim: a double-submitted popup callback must not exchange twice. The code is
        // single-use, so the loser's exchange would fail and mark a SUCCESSFUL attempt failed.
        $claimed = EmbeddedSignupAttempt::query()->whereKey($attempt->id)
            ->where('status', SignupStatus::Started)
            ->update(['status' => SignupStatus::Captured->value, 'updated_at' => now()]);
        if ($claimed === 0) {
            throw WhatsappException::signupInvalid('This signup is already being completed.');
        }
        $attempt->status = SignupStatus::Captured;
        $attempt->syncOriginalAttribute('status');

        if (! in_array($event, [SignupEvent::Finish, SignupEvent::FinishOnlyWaba, SignupEvent::FinishBusinessApp], true)) {
            $attempt->fail('Unsupported Embedded Signup completion: '.$data['event'], 'unsupported_event');
            throw WhatsappException::signupInvalid('This signup type is not supported. Start again from Connect WhatsApp.');
        }

        $coexistence = $event === SignupEvent::FinishBusinessApp;
        if ($coexistence && $attempt->flow !== 'coexistence') {
            $attempt->fail('Business app onboarding returned for a standard signup.', 'flow_mismatch');
            throw WhatsappException::coexistenceUnavailable();
        }

        $phoneNumberId = $event === SignupEvent::FinishOnlyWaba ? null : ($data['phone_number_id'] ?? null);

        $attempt->forceFill([
            'event' => $event->value,
            'waba_id' => $data['waba_id'],
            'phone_number_id' => $phoneNumberId,
            'meta_business_id' => $data['business_id'] ?? null,
            'meta_user_id' => $data['meta_user_id'] ?? null,
            'meta_session_id' => $data['session_id'] ?? null,
            'session_payload' => array_filter([
                'event' => $event->value, 'waba_id' => $data['waba_id'], 'phone_number_id' => $phoneNumberId,
                'business_id' => $data['business_id'] ?? null,
            ]),
        ])->save();

        // The popup finishing is NOT onboarding: so far the IDs are only captured (signup_captured).
        // 1. Exchange the code now — it expires 30 seconds after the popup closes.
        $attempt->forceFill(['status' => SignupStatus::Exchanging])->save();
        try {
            $token = $this->graph->exchangeCode($data['code']);
            $attempt->markStep('exchange_code', 'done');
            $debug = $this->graph->debugToken($token);
        } catch (MetaApiException $e) {
            $attempt->markStep('exchange_code', 'failed', $e->getMessage());
            $attempt->fail($e->getMessage(), (string) $e->metaCode);
            throw WhatsappException::meta('Meta rejected the signup. Please run Connect WhatsApp again.', $e->metaCode, $e->fbtraceId);
        }

        // 2. The token must actually grant this WABA — never trust asset IDs from the browser.
        if (($debug['is_valid'] ?? false) !== true || ! $this->grantsWaba($debug, $data['waba_id'])) {
            $attempt->fail('Business token does not grant access to the submitted WABA.', 'waba_not_granted');
            throw WhatsappException::signupInvalid('The WhatsApp account you selected was not shared with 10X Engage. Start again and select it in the popup.');
        }

        // 3. The WABA must not still be subscribed to another provider's app. Checked server-side
        //    BEFORE anything is stored or subscribed, so a blocked number is never "onboarded".
        $this->assertNotSubscribedElsewhere($attempt, $data['waba_id'], $token);

        // 4. Persist token, WABA and number atomically (plan limit enforced under a lock).
        //    Failures are recorded AFTER the rollback, otherwise the rollback would undo them.
        try {
            $waba = $this->entitlements->withinLimit($tenant, FeatureKey::WhatsappNumbers, $this->newNumberSlots($phoneNumberId),
                fn () => DB::transaction(fn () => $this->persist($tenant, $attempt, $token, $debug, $data['waba_id'], $phoneNumberId, $coexistence)));
        } catch (\Throwable $e) {
            $attempt->refresh()->fail($e->getMessage(), $e instanceof DomainException ? $e->errorCode()->value : 'persist_failed');

            throw $e;
        }

        $attempt->forceFill(['status' => SignupStatus::Provisioning, 'waba_account_id' => $waba->id])->save();

        $this->audit->record('whatsapp.signup_completed', $waba, after: [
            'waba_id' => $waba->waba_id, 'phone_number_id' => $phoneNumberId, 'flow' => $attempt->flow,
        ]);

        ProvisionWhatsappAccount::dispatch($attempt->id);

        return $attempt->refresh();
    }

    /** @param array{current_step?: ?string, error_code?: ?string, error_message?: ?string, session_id?: ?string} $data */
    public function cancel(EmbeddedSignupAttempt $attempt, array $data): EmbeddedSignupAttempt
    {
        if ($attempt->status->isTerminal() || $attempt->status !== SignupStatus::Started) {
            return $attempt;
        }

        $attempt->forceFill([
            'status' => SignupStatus::Cancelled,
            'event' => SignupEvent::Cancel->value,
            'current_step' => $data['current_step'] ?? null,
            'error_code' => $data['error_code'] ?? null,
            'error_message' => $data['error_message'] ?? null,
            'meta_session_id' => $data['session_id'] ?? null,
            'finished_at' => now(),
        ])->save();

        return $attempt;
    }

    /** @param array<string, mixed> $debug */
    private function persist(Tenant $tenant, EmbeddedSignupAttempt $attempt, string $token, array $debug, string $wabaId, ?string $phoneNumberId, bool $coexistence): WabaAccount
    {
        // WABAs are unique platform-wide: a WABA can never be attached to two workspaces.
        $owner = $this->context->bypass(fn () => WabaAccount::query()->where('waba_id', $wabaId)->lockForUpdate()->first());
        if ($owner !== null && $owner->tenant_id !== $tenant->id) {
            throw WhatsappException::wabaOwnedByAnotherWorkspace();
        }

        $accessToken = MetaAccessToken::query()->create([
            'secret_id' => $this->secrets->put('meta.business_token', $token, $tenant->id),
            'token_type' => 'business',
            'meta_app_id' => (string) ($debug['app_id'] ?? $this->graph->appId()),
            'meta_subject_id' => isset($debug['user_id']) ? (string) $debug['user_id'] : null,
            'scopes' => $debug['scopes'] ?? null,
            'granular_scopes' => $debug['granular_scopes'] ?? null,
            'expires_at' => ! empty($debug['expires_at']) ? now()->setTimestamp((int) $debug['expires_at']) : null,
            'data_access_expires_at' => ! empty($debug['data_access_expires_at']) ? now()->setTimestamp((int) $debug['data_access_expires_at']) : null,
            'last_validated_at' => now(),
        ]);

        // Re-running signup rotates the token: retire the previous one.
        $previous = $owner?->access_token_id;

        /** @var WabaAccount $waba */
        $waba = $owner ?? new WabaAccount(['waba_id' => $wabaId]);
        $waba->fill([
            'access_token_id' => $accessToken->id,
            'meta_business_id' => $attempt->meta_business_id ?? $waba->getAttribute('meta_business_id'),
            'status' => WabaStatus::Connected,
            'connected_at' => $waba->connected_at ?? now(),
            'disconnected_at' => null,
        ])->save();

        if ($previous !== null && $previous !== $accessToken->id) {
            $old = MetaAccessToken::query()->find($previous);
            if ($old !== null) {
                $old->forceFill(['revoked_at' => now()])->save();
                $this->secrets->destroy($old->secret_id);
            }
        }

        if ($phoneNumberId !== null) {
            $number = $this->context->bypass(fn () => PhoneNumber::query()->where('phone_number_id', $phoneNumberId)->lockForUpdate()->first());
            if ($number !== null && $number->tenant_id !== $tenant->id) {
                throw WhatsappException::wabaOwnedByAnotherWorkspace();
            }

            $type = $coexistence ? OnboardingType::Coexistence : OnboardingType::NewNumber;
            $number ??= new PhoneNumber(['phone_number_id' => $phoneNumberId]);
            $number->fill([
                'waba_account_id' => $waba->id,
                'status' => $number->exists && $number->status === PhoneNumberStatus::Connected ? PhoneNumberStatus::Connected : PhoneNumberStatus::Pending,
                'onboarding_type' => $type,
                'coexistence_status' => $coexistence ? CoexistenceStatus::SyncPending : CoexistenceStatus::None,
                'max_mps' => $coexistence ? (int) config('engage.meta.coexistence_max_mps', 20) : (int) config('engage.meta.default_max_mps', 80),
                'capabilities' => PhoneNumber::capabilitiesFor($type),
            ])->save();
        }

        return $waba;
    }

    private function assertNotSubscribedElsewhere(EmbeddedSignupAttempt $attempt, string $wabaId, string $token): void
    {
        if (! config('engage.meta.block_other_subscribed_apps', true)) {
            return;
        }

        try {
            $apps = $this->graph->listSubscribedApps($wabaId, $token);
        } catch (MetaApiException $e) {
            // Fail closed: without an answer from Meta we cannot know the number is free.
            $attempt->markStep('check_other_apps', 'failed', $e->getMessage());
            $attempt->fail($e->getMessage(), (string) ($e->metaCode ?? $e->httpStatus));
            throw WhatsappException::meta('We could not check whether this number is already connected to another application. Please try again.', $e->metaCode, $e->fbtraceId);
        }

        $attempt->forceFill(['session_payload' => ($attempt->session_payload ?? []) + ['subscribed_apps' => $apps]])->save();

        $allowed = array_merge([$this->graph->appId()], array_map('strval', (array) config('engage.meta.allowed_other_app_ids', [])));
        $conflicts = array_values(array_filter($apps, fn (array $app) => ! in_array($app['id'], $allowed, true)));
        $sameApp = false;

        // Our own Meta app is already subscribed, but this installation has never connected the
        // account: another 10X Engage environment sharing the app (production / staging) owns it.
        if ($conflicts === [] && config('engage.meta.block_other_environments', true) && ! $this->knownHere($wabaId)) {
            $conflicts = array_values(array_filter($apps, fn (array $app) => $app['id'] === $this->graph->appId()));
            $sameApp = $conflicts !== [];
        }

        if ($conflicts === []) {
            $attempt->markStep('check_other_apps', 'done');

            return;
        }

        $message = WhatsappException::subscribedElsewhereMessage($conflicts, $sameApp);
        $attempt->markStep('check_other_apps', 'failed', $message);
        $attempt->forceFill(['session_payload' => ['conflicting_apps' => $conflicts, 'conflict_same_app' => $sameApp] + ($attempt->session_payload ?? [])]);
        $attempt->fail($message, ErrorCode::NumberSubscribedElsewhere->value);

        $this->audit->record('whatsapp.signup_blocked', $attempt, meta: [
            'reason' => ErrorCode::NumberSubscribedElsewhere->value,
            'waba_id' => $wabaId,
            'phone_number_id' => $attempt->phone_number_id,
            'apps' => $conflicts,
            'same_app' => $sameApp,
        ]);

        throw WhatsappException::subscribedToAnotherApp($conflicts, $sameApp);
    }

    /** Has THIS installation connected the WABA (and so may legitimately hold our app's subscription)? */
    private function knownHere(string $wabaId): bool
    {
        /** @var ?WabaAccount $waba */
        $waba = $this->context->bypass(fn () => WabaAccount::query()->where('waba_id', $wabaId)->first());

        return $waba !== null && ($waba->status === WabaStatus::Connected || $waba->is_subscribed_to_webhooks);
    }

    /** @param array<string, mixed> $debug */
    private function grantsWaba(array $debug, string $wabaId): bool
    {
        foreach ((array) ($debug['granular_scopes'] ?? []) as $scope) {
            if (($scope['scope'] ?? null) === 'whatsapp_business_management') {
                $targets = $scope['target_ids'] ?? null;

                // No target_ids = the permission covers every asset the token can reach.
                return $targets === null || in_array($wabaId, array_map('strval', (array) $targets), true);
            }
        }

        return false;
    }

    private function newNumberSlots(?string $phoneNumberId): int
    {
        if ($phoneNumberId === null) {
            return 0;
        }

        $existing = $this->context->bypass(fn () => PhoneNumber::query()->where('phone_number_id', $phoneNumberId)
            ->whereIn('status', [PhoneNumberStatus::Pending, PhoneNumberStatus::Connected])->exists());

        return $existing ? 0 : 1;
    }

    private function assertConfigured(): void
    {
        if (blank(config('engage.meta.app_id')) || blank(config('engage.meta.app_secret')) || blank(config('engage.meta.embedded_signup_config_id'))) {
            throw WhatsappException::notConfigured();
        }
    }
}
