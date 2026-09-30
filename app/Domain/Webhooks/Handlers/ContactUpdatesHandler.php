<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Services\ConsentService;
use App\Domain\Messaging\Services\ContactIdentity;
use App\Domain\Messaging\Services\ContactResolver;
use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;

/**
 * field `user_id_update`: a user's BSUID changed (previous → current).
 * field `user_preferences`: the user stopped / resumed marketing messages inside WhatsApp.
 */
final class ContactUpdatesHandler implements WebhookHandler
{
    public function __construct(
        private readonly ContactResolver $contacts,
        private readonly ConsentService $consent,
    ) {}

    public function fields(): array
    {
        return ['user_id_update', 'user_preferences'];
    }

    public function handle(WebhookChange $change): ProcessResult
    {
        if ($change->waba === null) {
            return ProcessResult::Ignored;
        }

        return $change->field === 'user_id_update' ? $this->userIdUpdate($change->value) : $this->preferences($change->value);
    }

    /** @param array<string, mixed> $v */
    private function userIdUpdate(array $v): ProcessResult
    {
        foreach ((array) ($v['user_id_update'] ?? []) as $u) {
            $previous = $u['user_id']['previous'] ?? null;
            $current = $u['user_id']['current'] ?? null;
            if (! is_string($current) || $current === '') {
                continue;
            }

            $contact = (is_string($previous) ? Contact::query()->withTrashed()->where('bsuid', $previous)->first() : null)
                ?? (isset($u['wa_id']) ? Contact::query()->withTrashed()->where('wa_id', (string) $u['wa_id'])->first() : null);

            if ($contact !== null && ! Contact::query()->withTrashed()->where('bsuid', $current)->whereKeyNot($contact->id)->exists()) {
                $contact->forceFill(array_filter([
                    'bsuid' => $current,
                    'parent_bsuid' => $u['parent_user_id']['current'] ?? null,
                ]))->save();
            }
        }

        return ProcessResult::Processed;
    }

    /** @param array<string, mixed> $v */
    private function preferences(array $v): ProcessResult
    {
        foreach ((array) ($v['user_preferences'] ?? []) as $p) {
            if (($p['category'] ?? null) !== 'marketing_messages') {
                continue;
            }

            $contact = $this->contacts->resolve(new ContactIdentity(
                isset($p['wa_id']) ? (string) $p['wa_id'] : null,
                isset($p['user_id']) ? (string) $p['user_id'] : null,
            ), 'inbound');

            if ($contact !== null) {
                $this->consent->setMarketingPreference($contact, strtolower((string) ($p['value'] ?? '')) === 'stop', $p['detail'] ?? null);
            }
        }

        return ProcessResult::Processed;
    }
}
