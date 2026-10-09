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
use App\Notifications\ReconnectRequiredNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps our records true when a customer takes our access away at Meta.
 *
 * There are three ways we find out, and all of them end in markDisconnected():
 *   1. the account_update webhook says so (PARTNER_REMOVED, ACCOUNT_OFFBOARDED …);
 *   2. Meta refuses one of our API calls with an authorisation error (verify());
 *   3. a check every two minutes asks Meta whether each connected account, and each of its numbers,
 *      is still ours to use.
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
     * Meta refused a call. A dead token (190) is conclusive. A permission error can be about one
     * feature only, so before disconnecting anything we ask Meta the simplest possible question
     * about the account; only if that is refused too is the account marked disconnected.
     * Must run inside the workspace's tenant context. Returns true when it disconnected the account.
     */
    public function verify(WabaAccount $waba, ?int $metaCode = null): bool
    {
        if ($waba->status !== WabaStatus::Connected) {
            return false;
        }

        if ($metaCode !== 190) {
            try {
                $token = $this->credentials->tokenFor($waba);
                $this->graph->getWaba($waba->waba_id, $token);

                // The account still accepts us. A single number can be taken away on its own, though
                // (disconnected inside the WhatsApp Business app, or removed from the account).
                return $this->verifyNumbers($waba, $token);
            } catch (MetaApiException $e) {
                if (! self::looksRevoked($e)) {
                    return false; // an outage or an unrelated error: never disconnect on that
                }
                $metaCode = $e->metaCode;
            } catch (Throwable) {
                return false; // no usable token on our side is handled elsewhere; do not guess
            }
        }

        $this->markDisconnected($waba, $waba->phoneNumbers()->get(), 'access_revoked', ['meta_code' => $metaCode]);

        return true;
    }

    /**
     * Asks Meta about each connected number of an account we still have access to, and marks as
     * disconnected the ones that are gone. A number counts as gone only on a clear signal:
     *   • Meta says it does not exist or we may not see it any more;
     *   • it was on the WhatsApp Business app (coexistence) and Meta now says it is not;
     *   • it was registered on the Cloud API and Meta now says it is registered nowhere.
     * An outage or any other error changes nothing.
     */
    private function verifyNumbers(WabaAccount $waba, string $token): bool
    {
        $gone = collect();
        foreach ($waba->phoneNumbers()->where('status', PhoneNumberStatus::Connected->value)->get() as $number) {
            try {
                $node = $this->graph->getPhoneNumber($number->phone_number_id, $token);
            } catch (MetaApiException $e) {
                if (self::looksRevoked($e)) {
                    $gone->push($number);
                }

                continue;
            } catch (Throwable) {
                continue;
            }

            $leftTheApp = $number->isCoexistence() && $number->getAttribute('is_on_biz_app') === true && ($node['is_on_biz_app'] ?? null) === false;
            $deregistered = $number->getAttribute('platform_type') === 'CLOUD_API' && ($node['platform_type'] ?? null) === 'NOT_APPLICABLE';
            if ($leftTheApp || $deregistered) {
                $gone->push($number);
            }
            $this->sync->applyPhoneNumber($number, $node); // remember what Meta says now, for the next comparison
        }

        if ($gone->isEmpty()) {
            return false;
        }
        $this->markDisconnected($waba, $gone, 'offboarded', ['detected_by' => 'number_check']);

        return true;
    }

    /**
     * The single place a WhatsApp account or some of its numbers become "disconnected" because of
     * something that happened at Meta. Marks them, drops the token when nothing is left to use it
     * for, records it, and tells the workspace (email to owners, bell for those who manage channels).
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
                ])->save();
            }

            // Every number of the account is gone, or our access as a whole is: keep no usable credential.
            $allGone = $waba->phoneNumbers()->where('status', '!=', PhoneNumberStatus::Disconnected->value)->doesntExist();
            if ($allGone || in_array($reason, ['partner_removed', 'access_revoked'], true)) {
                $this->channels->revokeToken($waba);
                $waba->forceFill(['status' => WabaStatus::Disconnected, 'disconnected_at' => $waba->disconnected_at ?? now(), 'disconnect_reason' => $reason, 'is_subscribed_to_webhooks' => false])->save();
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
