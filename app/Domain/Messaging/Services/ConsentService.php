<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Models\ConsentEvent;
use App\Domain\Messaging\Models\Contact;

/**
 * Opt-in / opt-out. STOP-style keywords are honoured automatically on inbound text; an
 * opted-out contact cannot be messaged until they send START (or a workspace records consent).
 */
final class ConsentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return 'opt_out'|'opt_in'|null */
    public function keywordIntent(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $normalized = mb_strtolower(trim((string) preg_replace('/[\p{P}\p{S}\s]+/u', ' ', $text)));
        if ($normalized === '' || mb_strlen($normalized) > 24) {
            return null; // only whole-message keywords, never "please don't stop sending"
        }

        if (in_array($normalized, array_map('mb_strtolower', (array) config('engage.messaging.stop_keywords')), true)) {
            return 'opt_out';
        }
        if (in_array($normalized, array_map('mb_strtolower', (array) config('engage.messaging.start_keywords')), true)) {
            return 'opt_in';
        }

        return null;
    }

    public function optOut(Contact $contact, string $source, ?string $detail = null, ?string $messageId = null, ?string $membershipId = null): void
    {
        if ($contact->consent_state === ConsentState::OptedOut) {
            return;
        }

        $contact->forceFill(['consent_state' => ConsentState::OptedOut, 'opted_out_at' => now()])->save();
        $this->record($contact, 'opted_out', $source, $detail, $messageId, $membershipId);
    }

    public function optIn(Contact $contact, string $source, ?string $detail = null, ?string $messageId = null, ?string $membershipId = null): void
    {
        if ($contact->consent_state === ConsentState::OptedIn) {
            return;
        }

        $contact->forceFill(['consent_state' => ConsentState::OptedIn, 'opted_in_at' => now(), 'opted_out_at' => null])->save();
        $this->record($contact, 'opted_in', $source, $detail, $messageId, $membershipId);
    }

    /** Meta user_preferences webhook: the user stopped / resumed marketing messages in WhatsApp. */
    public function setMarketingPreference(Contact $contact, bool $optedOut, ?string $detail = null): void
    {
        if ($contact->marketing_opted_out === $optedOut) {
            return;
        }

        $contact->forceFill(['marketing_opted_out' => $optedOut])->save();
        $this->record($contact, $optedOut ? 'marketing_opted_out' : 'marketing_opted_in', 'meta_preference', $detail, null, null);
    }

    private function record(Contact $contact, string $action, string $source, ?string $detail, ?string $messageId, ?string $membershipId): void
    {
        ConsentEvent::query()->create([
            'contact_id' => $contact->id,
            'action' => $action,
            'source' => $source,
            'detail' => $detail !== null ? mb_substr($detail, 0, 190) : null,
            'message_id' => $messageId,
            'membership_id' => $membershipId,
        ]);

        $this->audit->record("contact.{$action}", $contact, meta: ['source' => $source]);
    }
}
