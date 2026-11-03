<?php

declare(strict_types=1);

namespace App\Application\WhatsApp;

use App\Application\Notifications\WorkspaceMailer;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditLogger;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use App\Notifications\InternalAlertNotification;
use App\Notifications\ReconnectRequiredNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Keeps our records true when a customer takes our access away at Meta, without ever turning a
 * monitoring guess into a disconnection.
 *
 * Only real signals DISCONNECT (markDisconnected()):
 *   • Meta's account_update webhook: PARTNER_REMOVED, PARTNER_APP_UNINSTALLED, ACCOUNT_DELETED
 *     (access gone) and ACCOUNT_OFFBOARDED (a WhatsApp Business app number taken off the platform);
 *   • an invalid token (error 190) confirmed by asking Meta about the account itself.
 * The credential is revoked only when access as a whole is gone (partner removed / confirmed 190),
 * never for a single number.
 *
 * Polling (every 10 minutes, and after a refused API call) only raises SUSPICION: a number or an
 * account is marked "needs attention", the 10X Engage team is alerted, and nothing stops working.
 * Only after ATTENTION_AFTER_CHECKS failed checks in a row, spanning at least ATTENTION_AFTER_MINUTES,
 * with no webhook from that number (or account) in that time. Every detection stores Meta's actual
 * response. A healthy answer clears the warning.
 *
 * Nothing is deleted: conversations, contacts and history stay, and reconnecting restores service.
 */
final class AccessRevocation
{
    public const REASONS = [
        'partner_removed' => '10X Engage was removed as a partner on your WhatsApp Business account.',
        'offboarded' => 'The number was disconnected from the WhatsApp Business app.',
        'access_revoked' => 'Meta no longer accepts our access to your WhatsApp Business account. This happens when 10X Engage is removed in Meta Business Settings, or its access expires.',
        'manual' => 'The account was disconnected from Channels.',
    ];

    /** What a "needs attention" flag means, for people. */
    public const ATTENTION = [
        'number_refused' => 'Meta refused to show us this number.',
        'left_business_app' => 'Meta reports this number is no longer on the WhatsApp Business app.',
        'deregistered' => 'Meta reports this number is not registered on the Cloud API.',
        'account_refused' => 'Meta refused to show us this WhatsApp Business account.',
    ];

    /** Suspicion becomes "needs attention" only after this many failed checks in a row… */
    public const ATTENTION_AFTER_CHECKS = 3;

    /** …spanning at least this long, with no webhook from Meta for it in that time. */
    public const ATTENTION_AFTER_MINUTES = 15;

    public function __construct(
        private readonly TenantContext $context,
        private readonly GraphClient $graph,
        private readonly WhatsappCredentials $credentials,
        private readonly ManageChannels $channels,
        private readonly AuditLogger $audit,
        private readonly PhoneNumberSync $sync,
    ) {}

    /** Does this error mean "you are no longer allowed", as opposed to a bad request or an outage? */
    public static function looksRevoked(MetaApiException $e): bool
    {
        // 190: the token is invalid, expired or revoked. 10 / 200–299: permission denied.
        // 100 with subcode 33: "object does not exist or cannot be loaded due to missing permissions".
        return $e->isAuthError() || ($e->metaCode === 100 && $e->metaSubcode === 33);
    }

    /**
     * Meta's response, as stored with every detection (no token, no message bodies).
     *
     * @param  MetaApiException|array<string, mixed>  $response
     * @return array<string, mixed>
     */
    public static function describe(MetaApiException|array $response): array
    {
        if ($response instanceof MetaApiException) {
            return array_filter([
                'http_status' => $response->httpStatus,
                'code' => $response->metaCode,
                'subcode' => $response->metaSubcode,
                'message' => mb_substr((string) ($response->error['message'] ?? $response->getMessage()), 0, 200),
                'fbtrace_id' => $response->fbtraceId,
            ], fn ($v) => $v !== null && $v !== '');
        }

        return array_intersect_key($response, array_flip(['platform_type', 'is_on_biz_app', 'code_verification_status', 'status', 'name_status', 'quality_rating']));
    }

    /**
     * Asks Meta about the account, then about each connected number. Called by the periodic check and
     * after one of our API calls was refused. Only an invalid token confirmed on the account itself
     * disconnects; everything else at most marks "needs attention". Must run inside the workspace's
     * tenant context. Returns true when it disconnected the account.
     */
    public function verify(WabaAccount $waba, ?int $metaCode = null): bool
    {
        if ($waba->status !== WabaStatus::Connected) {
            return false;
        }

        try {
            $token = $this->credentials->tokenFor($waba);
        } catch (Throwable) {
            return false; // no usable token on our side is handled elsewhere; do not guess
        }

        try {
            $this->graph->getWaba($waba->waba_id, $token);
        } catch (MetaApiException $e) {
            if ($e->metaCode === 190) {
                // Confirmed: Meta rejects the credential for the account itself.
                $this->markDisconnected($waba, $waba->phoneNumbers()->get(), 'access_revoked', [
                    'detected_by' => 'account_check', 'trigger_code' => $metaCode, 'meta_response' => self::describe($e),
                ]);

                return true;
            }
            if (self::looksRevoked($e)) {
                $this->suspect($waba, $waba, 'account_refused', $e, null);
            }

            return false; // an outage or an unrelated error never changes anything
        }

        $this->cleared($waba, $waba);
        $this->verifyNumbers($waba, $token);

        return false;
    }

    /**
     * Each connected number of an account we still have access to. Suspicious: Meta refuses the number,
     * a Business app number is reported off the app, or a Cloud API number is reported registered
     * nowhere. A field Meta did not return is "unknown", never a change.
     */
    private function verifyNumbers(WabaAccount $waba, string $token): void
    {
        foreach ($waba->phoneNumbers()->where('status', PhoneNumberStatus::Connected->value)->get() as $number) {
            try {
                $node = $this->graph->getPhoneNumber($number->phone_number_id, $token);
            } catch (MetaApiException $e) {
                if (self::looksRevoked($e)) {
                    $this->suspect($number, $waba, 'number_refused', $e, $number->phone_number_id);
                }

                continue;
            } catch (Throwable) {
                continue;
            }

            $onApp = array_key_exists('is_on_biz_app', $node) ? $node['is_on_biz_app'] : null;
            $platform = array_key_exists('platform_type', $node) ? $node['platform_type'] : null;
            $leftTheApp = $number->isCoexistence() && $number->getAttribute('is_on_biz_app') === true && $onApp === false;
            $deregistered = $number->getAttribute('platform_type') === 'CLOUD_API' && $platform === 'NOT_APPLICABLE';

            if ($leftTheApp || $deregistered) {
                // What we stored stays as it was, so the same change is seen again on the next check.
                $this->suspect($number, $waba, $leftTheApp ? 'left_business_app' : 'deregistered', $node, $number->phone_number_id);

                continue;
            }

            $this->cleared($number, $waba);
            $this->sync->applyPhoneNumber($number, $node); // remember what Meta says now, for the next comparison
        }
    }

    /**
     * One failed check. Counted in a row; the warning is raised only once the run is long enough and
     * nothing has come in from Meta for this number (or account) since the run started.
     */
    /** @param MetaApiException|array<string, mixed> $response */
    private function suspect(PhoneNumber|WabaAccount $subject, WabaAccount $waba, string $reason, MetaApiException|array $response, ?string $phoneNumberId): void
    {
        $key = $this->strikeKey($subject);
        /** @var array{count: int, first: int}|null $run */
        $run = Cache::get($key);
        $run = is_array($run) ? ['count' => $run['count'] + 1, 'first' => $run['first']] : ['count' => 1, 'first' => now()->getTimestamp()];

        // Messages (or delivery statuses) still arriving prove the number reaches us: start over.
        if ($this->hasTrafficSince($waba->waba_id, $phoneNumberId, $run['first'])) {
            $run = ['count' => 1, 'first' => now()->getTimestamp()];
        }
        Cache::put($key, $run, now()->addDay());

        $long = now()->getTimestamp() - $run['first'] >= self::ATTENTION_AFTER_MINUTES * 60;
        if ($run['count'] < self::ATTENTION_AFTER_CHECKS || ! $long || $subject->getAttribute('attention_reason') !== null) {
            return;
        }

        $detail = ['meta_response' => self::describe($response), 'checks' => $run['count'], 'since' => date(DATE_ATOM, $run['first'])];
        $subject->forceFill(['attention_reason' => $reason, 'attention_since' => now(), 'attention_detail' => $detail])->save();
        $this->audit->record('whatsapp.needs_attention', $waba, meta: $detail + [
            'reason' => $reason,
            'numbers' => $phoneNumberId !== null ? [$phoneNumberId] : $waba->phoneNumbers()->pluck('phone_number_id')->all(),
        ]);
        $this->alertTeam($subject, $waba, $reason, $detail);
    }

    /** A healthy answer: the run of failed checks ends and any warning is cleared. */
    private function cleared(PhoneNumber|WabaAccount $subject, WabaAccount $waba): void
    {
        Cache::forget($this->strikeKey($subject));
        if ($subject->getAttribute('attention_reason') === null) {
            return;
        }
        $was = $subject->getAttribute('attention_reason');
        $subject->forceFill(['attention_reason' => null, 'attention_since' => null, 'attention_detail' => null])->save();
        $this->audit->record('whatsapp.attention_cleared', $waba, meta: ['reason' => $was] + ($subject instanceof PhoneNumber ? ['numbers' => [$subject->phone_number_id]] : []));
    }

    /** The 10X Engage team, not the workspace: a warning is ours to look into, not the client's to fix. */
    /** @param array<string, mixed> $detail */
    private function alertTeam(PhoneNumber|WabaAccount $subject, WabaAccount $waba, string $reason, array $detail): void
    {
        $tenant = $this->context->tenantOrNull();
        $what = $subject instanceof PhoneNumber ? (string) ($subject->display_phone_number ?? $subject->phone_number_id) : "WhatsApp account {$waba->waba_id}";
        $context = ['tenant_id' => $tenant?->id, 'workspace' => $tenant?->name, 'subject' => $what, 'reason' => $reason] + $detail;
        Log::warning('WhatsApp needs attention: '.self::ATTENTION[$reason], $context);

        $to = config('engage.alerts.email');
        if (is_string($to) && $to !== '') {
            Notification::route('mail', $to)->notify(new InternalAlertNotification(
                "WhatsApp needs attention: {$what} ({$tenant?->name})",
                self::ATTENTION[$reason].' Nothing was disconnected; the number keeps working until Meta confirms a real disconnection.',
                $context,
            ));
        }
    }

    /** Has Meta delivered a messages webhook (an inbound message or a delivery status) since then? */
    private function hasTrafficSince(string $wabaId, ?string $phoneNumberId, int $since): bool
    {
        return $this->context->bypass(fn () => DB::table('webhook_inbound_log')
            ->where('waba_id', $wabaId)
            ->where('field', 'messages')
            ->when($phoneNumberId, fn ($q, $id) => $q->where('phone_number_id', $id))
            ->where('received_at', '>=', date(DATE_ATOM, $since))
            ->exists());
    }

    private function strikeKey(Model $subject): string
    {
        return $subject instanceof PhoneNumber
            ? "meta-access-strike:number:{$subject->phone_number_id}"
            : "meta-access-strike:waba:{$subject->getAttribute('waba_id')}";
    }

    /**
     * Brings back numbers marked "offboarded" (by Meta's webhook, or by the old 2-minute check) once Meta
     * answers normally for them again: the account accepts our credential, the number is on the Cloud API
     * and, for a WhatsApp Business app number, still on the app. Runs from the periodic check. Returns how
     * many numbers were reconnected. Must run inside the workspace's tenant context.
     */
    public function recover(WabaAccount $waba): int
    {
        try {
            $token = $this->credentials->tokenFor($waba);
            $this->graph->getWaba($waba->waba_id, $token);
        } catch (Throwable) {
            return 0; // no usable credential, or the account refuses us: only a reconnect from Channels helps
        }

        $back = collect();
        $seen = [];
        foreach ($waba->phoneNumbers()->where('status', PhoneNumberStatus::Disconnected->value)->where('disconnect_reason', 'offboarded')->get() as $number) {
            try {
                $node = $this->graph->getPhoneNumber($number->phone_number_id, $token);
            } catch (Throwable) {
                continue;
            }
            $onCloudApi = ($node['platform_type'] ?? null) === 'CLOUD_API';
            $onApp = ! $number->isCoexistence() || ($node['is_on_biz_app'] ?? true) !== false;
            if ($onCloudApi && $onApp) {
                $back->push($number);
                $seen[$number->phone_number_id] = self::describe($node);
                $this->sync->applyPhoneNumber($number, $node);
            }
        }
        if ($back->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($waba, $back, $seen): void {
            foreach ($back as $number) {
                $number->forceFill([
                    'status' => PhoneNumberStatus::Connected,
                    'coexistence_status' => $number->coexistence_status === CoexistenceStatus::Offboarded ? CoexistenceStatus::Synced : $number->coexistence_status,
                    'disconnect_reason' => null,
                    'disconnected_at' => null,
                ])->save();
                Cache::forget($this->strikeKey($number));
            }
            if ($waba->status === WabaStatus::Disconnected) {
                $waba->forceFill(['status' => WabaStatus::Connected, 'disconnected_at' => null, 'disconnect_reason' => null])->save();
            }
            $this->audit->record('whatsapp.reconnected', $waba, meta: ['numbers' => $back->pluck('phone_number_id')->all(), 'detected_by' => 'number_check', 'meta_response' => $seen]);
        });

        return $back->count();
    }

    /**
     * The single place a WhatsApp account or some of its numbers become "disconnected" because Meta said
     * so (a webhook, or an invalid token confirmed on the account). Marks them, revokes our credential
     * only when access as a whole is gone, records why, and tells the workspace (email to owners, bell
     * for those who manage channels).
     *
     * @param  Collection<int, PhoneNumber>  $numbers  the numbers affected (all of the account's, or one)
     * @param  array<string, mixed>  $meta
     */
    public function markDisconnected(WabaAccount $waba, Collection $numbers, string $reason, array $meta = []): void
    {
        $tenant = $this->context->tenantOrNull();
        $newly = $numbers->filter(fn (PhoneNumber $n) => $n->status !== PhoneNumberStatus::Disconnected)->values();

        DB::transaction(function () use ($waba, $numbers, $reason, $meta): void {
            foreach ($numbers as $number) {
                $number->forceFill([
                    'status' => PhoneNumberStatus::Disconnected,
                    'coexistence_status' => $number->isCoexistence() ? CoexistenceStatus::Offboarded : $number->coexistence_status,
                    'disconnect_reason' => $number->getAttribute('disconnect_reason') ?? $reason,
                    'disconnected_at' => $number->getAttribute('disconnected_at') ?? now(),
                    'attention_reason' => null, 'attention_since' => null, 'attention_detail' => null,
                ])->save();
            }

            // Our access as a whole is gone: keep no usable credential. A number taken away on its own
            // (offboarded) never costs the account its credential: Meta can reconnect it by itself
            // (ACCOUNT_RECONNECTED), and recover() brings it back once Meta answers normally again.
            $accessGone = in_array($reason, ['partner_removed', 'access_revoked'], true);
            $allGone = $waba->phoneNumbers()->where('status', '!=', PhoneNumberStatus::Disconnected->value)->doesntExist();
            if ($accessGone) {
                $this->channels->revokeToken($waba);
            }
            if ($accessGone || $allGone) {
                $waba->forceFill(array_filter([
                    'status' => WabaStatus::Disconnected,
                    'disconnected_at' => $waba->disconnected_at ?? now(),
                    'disconnect_reason' => $reason,
                    'is_subscribed_to_webhooks' => $accessGone ? false : null,
                ], fn ($v) => $v !== null))->save();
            }

            $this->audit->record('whatsapp.'.$reason, $waba, meta: $meta + ['numbers' => $numbers->pluck('phone_number_id')->all()]);
        });

        if ($tenant !== null && $newly->isNotEmpty()) {
            $this->tell($tenant, $newly, $reason);
        }
    }

    /** @param Collection<int, PhoneNumber> $numbers */
    private function tell(Tenant $tenant, Collection $numbers, string $reason): void
    {
        $why = self::REASONS[$reason] ?? self::REASONS['access_revoked'];
        foreach ($numbers as $number) {
            app(WorkspaceMailer::class)->toOwners($tenant, new ReconnectRequiredNotification($tenant->name, (string) $number->display_phone_number, $why));
        }

        // The bell, for everyone who can do something about it.
        $names = $numbers->map(fn (PhoneNumber $n) => $n->verified_name ?? $n->display_phone_number)->implode(', ');
        $managers = TenantMembership::query()->active()->with('role')->get()->filter(fn (TenantMembership $m) => $m->role !== null && $m->role->grants(Permission::ChannelsManage));
        foreach ($managers as $member) {
            app(InboxTools::class)->notify($member, 'channel_disconnected', "WhatsApp disconnected: {$names}", $why.' Reconnect it in Channels to send and receive again.', '/channels');
        }
    }
}
