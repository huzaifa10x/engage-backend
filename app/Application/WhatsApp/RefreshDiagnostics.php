<?php

declare(strict_types=1);

namespace App\Application\WhatsApp;

use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\MetaAccessToken;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Diagnostics only (handover 15 Nov, I.3): when refreshing a WhatsApp account from Meta fails, one
 * log line says which account, what Meta answered (code, subcode, trace id) and what the token we
 * used can actually do, from Meta's debug_token: its type, app, whether it is valid, its scopes, and
 * whether the WhatsApp scopes are granted for this account (granular target IDs). Nothing is fixed
 * or retried here, and the token itself is never logged.
 */
final class RefreshDiagnostics
{
    private const WHATSAPP_SCOPES = ['whatsapp_business_management', 'whatsapp_business_messaging', 'business_management'];

    public function __construct(private readonly GraphClient $graph, private readonly TenantContext $context) {}

    public function log(WabaAccount $waba, MetaApiException $e, ?string $token, string $where): void
    {
        try {
            /** @var ?MetaAccessToken $stored */
            $stored = $waba->access_token_id !== null ? $this->context->bypass(fn () => MetaAccessToken::query()->find($waba->access_token_id)) : null;

            Log::warning('WhatsApp account refresh failed', [
                'where' => $where,
                'tenant_id' => $waba->tenant_id,
                'waba_account_id' => $waba->id,
                'waba_id' => $waba->waba_id,
                'meta_code' => $e->metaCode,
                'meta_subcode' => $e->metaSubcode,
                'http_status' => $e->httpStatus,
                'meta_message' => mb_substr($e->getMessage(), 0, 300),
                'fbtrace_id' => $e->fbtraceId,
                'stored_token' => $stored === null ? null : [
                    'id' => $stored->id,
                    'type' => $stored->token_type,
                    'app_id' => $stored->meta_app_id,
                    'subject_id' => $stored->meta_subject_id,
                    'scopes' => $stored->scopes,
                    'expires_at' => $stored->expires_at?->toIso8601String(),
                    'revoked_at' => $stored->revoked_at?->toIso8601String(),
                    'last_validated_at' => $stored->last_validated_at?->toIso8601String(),
                ],
                'debug_token' => $token !== null ? $this->debug($token, (string) $waba->waba_id) : ['error' => 'no usable token was stored for this account'],
            ]);
        } catch (Throwable $failure) {
            Log::warning('WhatsApp account refresh failed (diagnostics incomplete)', [
                'where' => $where, 'waba_account_id' => $waba->id, 'waba_id' => $waba->waba_id,
                'meta_code' => $e->metaCode, 'meta_subcode' => $e->metaSubcode, 'diagnostics_error' => $failure->getMessage(),
            ]);
        }
    }

    /** No usable token was stored for the account (expired, revoked or missing): nothing to ask Meta. */
    public function logWithoutToken(WabaAccount $waba, string $reason, string $where): void
    {
        $stored = $waba->access_token_id !== null ? $this->context->bypass(fn () => MetaAccessToken::query()->find($waba->access_token_id)) : null;
        Log::warning('WhatsApp account refresh failed', [
            'where' => $where, 'tenant_id' => $waba->tenant_id, 'waba_account_id' => $waba->id, 'waba_id' => $waba->waba_id,
            'reason' => $reason, 'stored_token' => $stored === null ? null : ['id' => $stored->id, 'type' => $stored->token_type,
                'expires_at' => $stored->expires_at?->toIso8601String(), 'revoked_at' => $stored->revoked_at?->toIso8601String()],
        ]);
    }

    /** @return array<string, mixed> what Meta says the token is and may do (never the token) */
    private function debug(string $token, string $wabaId): array
    {
        try {
            $d = $this->graph->debugToken($token);
        } catch (Throwable $e) {
            return ['error' => mb_substr($e->getMessage(), 0, 300)];
        }
        $granular = [];
        foreach ((array) ($d['granular_scopes'] ?? []) as $g) {
            if (! is_array($g) || ! in_array($g['scope'] ?? null, self::WHATSAPP_SCOPES, true)) {
                continue;
            }
            $targets = array_map('strval', (array) ($g['target_ids'] ?? []));
            $granular[(string) $g['scope']] = [
                // No target list means the scope applies to everything the user can reach.
                'covers_this_account' => $targets === [] || in_array($wabaId, $targets, true),
                'target_count' => count($targets),
            ];
        }

        return [
            'is_valid' => $d['is_valid'] ?? null,
            'type' => $d['type'] ?? null,
            'app_id' => $d['app_id'] ?? null,
            'user_id' => $d['user_id'] ?? null,
            'scopes' => array_values((array) ($d['scopes'] ?? [])),
            'whatsapp_scopes' => $granular,
            'expires_at' => isset($d['expires_at']) && (int) $d['expires_at'] > 0 ? date(DATE_ATOM, (int) $d['expires_at']) : 'never',
            'data_access_expires_at' => isset($d['data_access_expires_at']) && (int) $d['data_access_expires_at'] > 0 ? date(DATE_ATOM, (int) $d['data_access_expires_at']) : null,
            'error' => isset($d['error']) ? ['code' => $d['error']['code'] ?? null, 'subcode' => $d['error']['subcode'] ?? null, 'message' => $d['error']['message'] ?? null] : null,
        ];
    }
}
