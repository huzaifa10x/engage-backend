<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Application\WhatsApp\AccessRevocation;
use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\QualityEvent;
use Illuminate\Support\Collection;

/**
 * account_update: WABA lifecycle. PARTNER_REMOVED / ACCOUNT_OFFBOARDED disconnect (coexistence
 * clients disconnect from the app — Settings › Account › Business Platform); ACCOUNT_RECONNECTED
 * restores; DISABLED_UPDATE carries the ban state; violations and restrictions feed Quality & abuse.
 */
final class AccountUpdateHandler implements WebhookHandler
{
    public function __construct(
        private readonly AccessRevocation $revocation,
    ) {}

    public function fields(): array
    {
        return ['account_update'];
    }

    public function handle(WebhookChange $change): ProcessResult
    {
        $waba = $change->waba;
        if ($waba === null) {
            return ProcessResult::Ignored; // e.g. PARTNER_ADDED before signup completed; signup reads state itself
        }

        // Compared in upper case: Meta has sent these both ways ("PARTNER_REMOVED", "partner_removed").
        $event = strtoupper((string) ($change->value['event'] ?? 'UNKNOWN'));
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
