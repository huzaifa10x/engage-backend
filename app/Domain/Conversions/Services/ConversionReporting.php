<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Services;

use App\Application\WhatsApp\WhatsappCredentials;
use App\Domain\Audit\AuditLogger;
use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Conversions\Models\MetaDataset;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Exceptions\WhatsappException;
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
     * Whether the account's token carries whatsapp_business_manage_events for this account (debug_token,
     * granular target IDs when Meta lists them). The answer is stored on the account.
     */
    public function checkPermission(WabaAccount $waba, ?string $token = null): bool
    {
        try {
            $d = $this->graph->debugToken($token ?? $this->credentials->tokenFor($waba));
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
        $waba->forceFill(['events_permission' => $has, 'events_permission_checked_at' => now()])->save();

        return $has;
    }
}
