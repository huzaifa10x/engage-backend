<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Services\ContactIdentity;
use App\Domain\Messaging\Services\ContactResolver;
use App\Domain\Messaging\Services\MessageRecorder;
use App\Domain\Messaging\Services\StatusApplier;
use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use Illuminate\Support\Facades\Log;

/**
 * field `messages`: customer messages (value.messages[]) and delivery statuses (value.statuses[]).
 * Group messages are ignored (groups are not a product feature). Runs inside the tenant context
 * and one DB transaction per change; MessageNotYetKnown bubbles up so the change is retried.
 */
final class MessagesHandler implements WebhookHandler
{
    public function __construct(
        private readonly ContactResolver $contacts,
        private readonly MessageRecorder $recorder,
        private readonly StatusApplier $statuses,
    ) {}

    public function fields(): array
    {
        return ['messages'];
    }

    public function handle(WebhookChange $change): ProcessResult
    {
        $number = $change->number;
        if ($number === null) {
            return ProcessResult::Ignored; // number not (yet) known to us
        }
        if ($number->status === PhoneNumberStatus::Pending) {
            $number->forceFill(['status' => PhoneNumberStatus::Connected])->save(); // traffic proves it works
        }

        $v = $change->value;
        $contactBlocks = array_values(array_filter((array) ($v['contacts'] ?? []), 'is_array'));

        foreach ((array) ($v['messages'] ?? []) as $raw) {
            if (! is_array($raw) || isset($raw['group_id'])) {
                continue;
            }

            $identity = ContactIdentity::fromInbound($raw, $contactBlocks);
            $contact = $this->contacts->resolve($identity, 'inbound');
            if ($contact === null) {
                Log::warning('Inbound message without any user identifier.', ['wamid' => $raw['id'] ?? null]);

                continue;
            }

            if (($raw['type'] ?? null) === 'system') {
                $this->applyIdentityChange($contact, (array) ($raw['system'] ?? []));
            }

            $this->recorder->record($number, $contact, $raw, MessageOrigin::Customer);
        }

        foreach ((array) ($v['statuses'] ?? []) as $status) {
            if (is_array($status)) {
                $this->statuses->apply($status);
            }
        }

        // Status webhooks now carry usernames / BSUIDs: enrich contacts we already know.
        if (! empty($v['statuses'])) {
            foreach ($contactBlocks as $block) {
                $identity = ContactIdentity::fromContactBlock($block);
                if ($this->contacts->find($identity) !== null) {
                    $this->contacts->resolve($identity, 'inbound');
                }
            }
        }

        return ProcessResult::Processed;
    }

    /** system message `user_changed_user_id` / `user_changed_number`: new identifiers for the same person. @param array<string, mixed> $system */
    private function applyIdentityChange(Contact $contact, array $system): void
    {
        $newWaId = isset($system['wa_id']) ? (string) $system['wa_id'] : null;
        $newBsuid = isset($system['user_id']) ? (string) $system['user_id'] : null;

        $updates = array_filter([
            'wa_id' => $newWaId !== null && ! Contact::query()->withTrashed()->where('wa_id', $newWaId)->whereKeyNot($contact->id)->exists() ? $newWaId : null,
            'bsuid' => $newBsuid !== null && ! Contact::query()->withTrashed()->where('bsuid', $newBsuid)->whereKeyNot($contact->id)->exists() ? $newBsuid : null,
            'parent_bsuid' => isset($system['parent_user_id']) ? (string) $system['parent_user_id'] : null,
        ]);

        if ($updates !== []) {
            $contact->forceFill($updates)->save();
        }
    }
}
