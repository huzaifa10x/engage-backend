<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Services;

use App\Application\WhatsApp\WhatsappCredentials;
use App\Domain\Audit\AuditLogger;
use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Conversions\Models\ConversionEvent;
use App\Domain\Conversions\Models\MetaDataset;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Exceptions\WhatsappException;
use App\Domain\WhatsApp\Models\MetaAccessToken;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use Throwable;

/**
 * Turning conversion reporting on or off for one WhatsApp Business Account. Turning it on needs the
 * workspace flag and the whatsapp_business_manage_events permission on the account's own token;
 * then the account's dataset is created at Meta (or Meta returns the existing one) and saved.
 * Without the permission nothing is saved and the account shows "Not enabled".
 */
final class ConversionReporting
{
    public const PERMISSION = 'whatsapp_business_manage_events';

    public const ENABLED = 'enabled';

    public const NOT_ENABLED = 'not_enabled';     // the permission is missing: reconnect to grant it

    public const NOT_ALLOWED = 'not_allowed';     // the workspace is not switched on for conversions yet

    public function __construct(
        private readonly GraphClient $graph,
        private readonly WhatsappCredentials $credentials,
        private readonly AuditLogger $audit,
    ) {}

    /** Turns reporting on. Returns enabled | not_enabled | not_allowed. */
    public function enable(Tenant $tenant, WabaAccount $waba): string
    {
        if (! ConversionsAccess::allowed($tenant)) {
            return self::NOT_ALLOWED;
        }
        $token = $this->credentials->tokenFor($waba);
        if (! $this->checkPermission($waba, $token)) {
            return self::NOT_ENABLED;
        }
        try {
            $datasetId = $this->graph->createDataset($waba->waba_id, $token);
        } catch (MetaApiException $e) {
            if (WhatsappException::isPermissionError($e->metaCode)) {
                $waba->forceFill(['events_permission' => false, 'events_permission_checked_at' => now()])->save();
                $this->audit->record('conversions.not_enabled', $waba, meta: ['reason' => 'permission_missing'] + $e->context());

                return self::NOT_ENABLED;
            }
            throw $e;
        }

        $dataset = MetaDataset::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'waba_id' => $waba->waba_id],
            ['waba_account_id' => $waba->id, 'dataset_id' => $datasetId, 'enabled' => true],
        );
        $this->audit->record('conversions.enabled', $dataset, meta: ['waba_id' => $waba->waba_id, 'dataset_id' => $datasetId]);

        return self::ENABLED;
    }

    /** Turns reporting off. The dataset ID is kept for when it is turned on again. */
    public function disable(WabaAccount $waba): void
    {
        $dataset = MetaDataset::query()->where('waba_id', $waba->waba_id)->first();
        if ($dataset !== null && $dataset->enabled) {
            $dataset->forceFill(['enabled' => false])->save();
            $this->audit->record('conversions.disabled', $dataset, meta: ['waba_id' => $waba->waba_id]);
        }
    }

    /** The enabled dataset of an account, or null when reporting is off for it. */
    public function datasetFor(string $wabaId): ?MetaDataset
    {
        return MetaDataset::query()->where('waba_id', $wabaId)->where('enabled', true)->first();
    }

    /**
     * Whether the account's token can report conversions for this account. The answer is stored on the
     * account (Channels shows "Enabled" / "Not enabled").
     *
     *  1. debug_token lists whatsapp_business_manage_events for this account (granular target IDs when
     *     Meta lists them): yes.
     *  2. Otherwise Meta is asked directly: POST /{WABA_ID}/dataset with the token. Embedded Signup tokens
     *     do not always list the permission in debug_token although Meta accepts the calls. The call is
     *     safe to repeat: Meta returns the account's existing dataset. A permission error: no.
     *  3. Once Meta refused this account's events for a missing permission, the dataset answer is not
     *     enough: "Not enabled" until the account is reconnected (the refusal must be newer than the token).
     */
    public function checkPermission(WabaAccount $waba, ?string $token = null): bool
    {
        try {
            $token ??= $this->credentials->tokenFor($waba);
            $d = $this->graph->debugToken($token);
        } catch (Throwable) {
            return (bool) $waba->events_permission; // Meta unreachable: keep what we last knew
        }
        $has = in_array(self::PERMISSION, (array) ($d['scopes'] ?? []), true);
        foreach ((array) ($d['granular_scopes'] ?? []) as $g) {
            if (is_array($g) && ($g['scope'] ?? null) === self::PERMISSION) {
                $targets = array_map('strval', (array) ($g['target_ids'] ?? []));
                $has = $has && ($targets === [] || in_array($waba->waba_id, $targets, true));
            }
        }

        if (! $has) {
            if (self::refusedWhenSending($waba)) {
                $has = false;
            } else {
                try {
                    $this->graph->createDataset($waba->waba_id, $token);
                    $has = true;
                } catch (MetaApiException $e) {
                    if (! WhatsappException::isPermissionError($e->metaCode)) {
                        return (bool) $waba->events_permission; // not an answer about the permission
                    }
                }
            }
        }

        $waba->forceFill(['events_permission' => $has, 'events_permission_checked_at' => now()])->save();

        return $has;
    }

    /** Meta refused this account's events for a missing permission since its current token was saved. */
    public static function refusedWhenSending(WabaAccount $waba): bool
    {
        $since = MetaAccessToken::query()->whereKey($waba->access_token_id)->value('created_at');

        return ConversionEvent::query()->where('waba_id', $waba->waba_id)->where('status', ConversionEvent::SKIPPED)
            ->where('error_reason', ConversionEvent::SKIP_REASONS['missing_permission'])->whereNotNull('meta_response')
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>=', $since))->exists();
    }
}
