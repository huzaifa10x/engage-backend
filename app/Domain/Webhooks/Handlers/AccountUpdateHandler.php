<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Application\WhatsApp\AccessRevocation;
use App\Application\WhatsApp\MarketingMessages;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Jobs\VerifyMetaAccess;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\QualityEvent;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * account_update: WABA lifecycle. PARTNER_REMOVED / ACCOUNT_OFFBOARDED disconnect (coexistence
 * clients disconnect from the app — Settings › Account › Business Platform); ACCOUNT_RECONNECTED
 * restores; DISABLED_UPDATE carries the ban state; violations and restrictions feed Quality & abuse.
 * MM_LITE_TERMS_SIGNED (and the older AD_ACCOUNT_LINKED): the business accepted the Marketing Messages
 * terms — the account is onboarded and campaign marketing goes through the MM API from now on.
 */
final class AccountUpdateHandler implements WebhookHandler
{
    private const MM_SIGNED = ['MM_LITE_TERMS_SIGNED', 'AD_ACCOUNT_LINKED'];

    public function __construct(
        private readonly AccessRevocation $revocation,
        private readonly MarketingMessages $marketingMessages,
        private readonly TenantContext $context,
    ) {}

    public function fields(): array
    {
        return ['account_update'];
    }

    public function handle(WebhookChange $change): ProcessResult
    {
        // Compared in upper case: Meta has sent these both ways ("PARTNER_REMOVED", "partner_removed").
        $event = strtoupper((string) ($change->value['event'] ?? 'UNKNOWN'));

        $waba = $change->waba;
        if ($waba === null) {
            // Sent once for a whole business portfolio: every account of that business is onboarded.
            if (in_array($event, self::MM_SIGNED, true) && $this->signedForBusiness($change) > 0) {
                return ProcessResult::Processed;
            }

            return ProcessResult::Ignored; // e.g. PARTNER_ADDED before signup completed; signup reads state itself
        }
        $numbers = $this->affectedNumbers($change);

        switch ($event) {
            // The customer took our access away, or took the number off the platform:
            //   PARTNER_REMOVED          10X Engage removed as a partner (Meta Business Settings), API or coexistence
            //   PARTNER_APP_UNINSTALLED  our app removed from the business
            //   ACCOUNT_OFFBOARDED       coexistence: disconnected inside the WhatsApp Business app
            //   ACCOUNT_DELETED          the WhatsApp Business account itself was deleted
            case 'PARTNER_REMOVED':
            case 'PARTNER_APP_UNINSTALLED':
            case 'ACCOUNT_OFFBOARDED':
            case 'ACCOUNT_DELETED':
                $this->revocation->markDisconnected($waba, $numbers, $event === 'ACCOUNT_OFFBOARDED' ? 'offboarded' : 'partner_removed', [
                    'event' => $event,
                    'disconnection_info' => $change->value['disconnection_info'] ?? null,
                ]);
                break;

            case 'ACCOUNT_RECONNECTED':
                foreach ($numbers as $number) {
                    $number->forceFill(['status' => PhoneNumberStatus::Connected, 'disconnect_reason' => null, 'disconnected_at' => null])->save();
                }
                $waba->forceFill(['status' => WabaStatus::Connected, 'disconnected_at' => null, 'disconnect_reason' => null])->save();
                break;

            default:
                // An event this code does not know by name (Meta adds and renames them). Rather than wait for
                // the periodic check, ask Meta right now whether the account and its numbers are still ours:
                // if this was a disconnection under another name, it shows within seconds.
                if (Cache::add('meta-access-check:'.$waba->waba_id, 1, 20)) {
                    VerifyMetaAccess::dispatch($waba->waba_id, null);
                }
                break;

            case 'MM_LITE_TERMS_SIGNED':
            case 'AD_ACCOUNT_LINKED':
                $this->marketingMessages->termsSigned($waba, $change->value);
                break;

            case 'DISABLED_UPDATE':
                $waba->forceFill(['ban_state' => $change->value['ban_info']['waba_ban_state'] ?? null])->save();
                break;
        }

        QualityEvent::query()->create([
            'waba_account_id' => $waba->id,
            'phone_number_id' => $numbers->count() === 1 ? $numbers->first()?->id : null,
            'event_type' => 'account_update.'.mb_strtolower($event),
            'new_value' => $this->summary($change->value),
            'payload' => $change->value,
            'occurred_at' => $change->occurredAt,
        ]);

        return ProcessResult::Processed;
    }

    /** Terms accepted at business level (no WABA on the entry): onboard every account of that business, each in its own workspace. */
    private function signedForBusiness(WebhookChange $change): int
    {
        $business = $change->value['waba_info']['owner_business_id'] ?? $change->value['owner_business_id'] ?? null;
        if (! is_string($business) && ! is_int($business)) {
            return 0;
        }

        $wabas = $this->context->bypass(fn () => WabaAccount::query()->where('meta_business_id', (string) $business)->get());
        foreach ($wabas as $waba) {
            $tenant = $this->context->bypass(fn () => Tenant::query()->find($waba->tenant_id));
            if ($tenant !== null) {
                $this->context->run($tenant, fn () => $this->marketingMessages->termsSigned($waba, $change->value));
            }
        }

        return $wabas->count();
    }

    /** @return Collection<int, PhoneNumber> */
    private function affectedNumbers(WebhookChange $change): Collection
    {
        $phone = $change->value['phone_number'] ?? null;
        if (is_string($phone) && ($number = $change->numberByDisplay($phone)) !== null) {
            return collect([$number]);
        }

        return $change->waba?->phoneNumbers()->get() ?? collect();
    }

    /** @param array<string, mixed> $value */
    private function summary(array $value): ?string
    {
        $candidate = $value['ban_info']['waba_ban_state']
            ?? $value['violation_info']['violation_type']
            ?? $value['disconnection_info']['reason']
            ?? null;

        return is_string($candidate) ? mb_substr($candidate, 0, 64) : null;
    }
}
