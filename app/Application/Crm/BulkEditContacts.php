<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditLogger;
use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Crm\Models\ContactField;
use App\Domain\Crm\Services\ContactStages;
use App\Domain\Crm\Services\CustomFields;
use App\Domain\Developer\Services\PublicPayload;
use App\Domain\Developer\Services\WebhookDispatcher;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\ConsentService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contacts & CRM › select contacts › Edit: one change applied to every selected contact, as if
 * each had been edited on its own (stage history, consent ledger, custom field rules, webhooks), in
 * one transaction. The request names the number of contacts the teammate confirmed; if that is no
 * longer the number found (some were deleted meanwhile), nothing changes and they confirm again.
 *
 * Changes: tags to add / remove; a custom field set or cleared; the pipeline stage (Owners and
 * Admins, as on the contact record); consent: opt-out, or opt-in with where they agreed — a bulk
 * opt-in without a stated source would make "opted in" meaningless.
 */
final class BulkEditContacts
{
    public const MAX = 500;

    public function __construct(
        private readonly TenantContext $context,
        private readonly TagCatalog $tags,
        private readonly CustomFields $fields,
        private readonly ContactStages $stages,
        private readonly ConsentService $consent,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $changes  tags_add, tags_remove, field {key, value|null}, stage {id|null}, consent {state, note}
     * @return array{matched: int, updated: int, skipped: array<string, int>}
     */
    public function apply(array $ids, array $changes, int $confirmed): array
    {
        $ids = array_values(array_unique($ids));
        $contacts = Contact::query()->whereIn('id', $ids)->orderBy('id')->get();
        if ($contacts->count() !== $confirmed) {
            throw ValidationException::withMessages(['confirm_count' => "{$contacts->count()} of the selected contacts can be edited now (some were deleted or are not yours to see). Review the selection and confirm again."]);
        }

        $add = $this->tags->ensure((array) ($changes['tags_add'] ?? []), 'tags_add');
        $remove = array_map(fn ($t) => mb_strtolower(trim((string) $t)), (array) ($changes['tags_remove'] ?? []));
        if (array_intersect(array_map('mb_strtolower', $add), $remove) !== []) {
            throw ValidationException::withMessages(['tags_remove' => 'A tag cannot be added and removed at the same time.']);
        }

        [$fieldKey, $fieldValue] = $this->field($changes['field'] ?? null);
        $stage = array_key_exists('stage', $changes) && is_array($changes['stage']) ? ['id' => $changes['stage']['id'] ?? null] : null;
        if ($stage !== null) {
            $role = $this->context->membership()?->role?->systemRole();
            if (! in_array($role, [SystemRole::Owner, SystemRole::Admin], true)) {
                throw ValidationException::withMessages(['stage' => 'Only Owners and Admins change the stage of several contacts at once.']);
            }
        }
        $consent = $this->consentChange($changes['consent'] ?? null);

        if ($add === [] && $remove === [] && $fieldKey === null && $stage === null && $consent === null) {
            throw ValidationException::withMessages(['changes' => 'Choose at least one change.']);
        }

        $updated = 0;
        $skipped = [];
        $membershipId = $this->context->membership()?->id;
        $optedOut = [];

        DB::transaction(function () use ($contacts, $add, $remove, $fieldKey, $fieldValue, $stage, $consent, $membershipId, &$updated, &$skipped, &$optedOut): void {
            foreach ($contacts as $contact) {
                $changed = false;

                if ($add !== [] || $remove !== []) {
                    $tags = array_values(array_filter(array_unique(array_merge((array) $contact->tags, $add)), fn ($t) => ! in_array(mb_strtolower((string) $t), $remove, true)));
                    if ($tags !== (array) $contact->tags) {
                        $contact->tags = $tags;
                    }
                }
                if ($fieldKey !== null) {
                    $values = (array) ($contact->custom_fields ?? []);
                    if ($fieldValue === null) {
                        unset($values[$fieldKey]);
                    } else {
                        $values[$fieldKey] = $fieldValue;
                    }
                    $contact->custom_fields = $values === [] ? null : $values;
                }
                if ($contact->isDirty()) {
                    $contact->save();
                    $changed = true;
                }

                if ($stage !== null && $this->stages->move($contact, $stage['id']) !== null) {
                    $changed = true;
                }

                if ($consent !== null) {
                    if ($consent['state'] === 'opted_out') {
                        if ($this->consent->optOut($contact, 'agent', $consent['note'], membershipId: $membershipId)) {
                            $changed = true;
                            $optedOut[] = $contact->id;
                        }
                    } elseif ($contact->consent_state === ConsentState::OptedOut) {
                        // A customer who opted out comes back only by their own START, or one by one with care.
                        $skipped['opted_out'] = ($skipped['opted_out'] ?? 0) + 1;
                    } elseif ($this->consent->optIn($contact, 'agent', $consent['note'], membershipId: $membershipId)) {
                        $changed = true;
                    }
                }

                if ($changed) {
                    $updated++;
                    app(WebhookDispatcher::class)->emit($contact->tenant_id, 'contact.updated', PublicPayload::contact($contact));
                }
            }
        });

        // Opted out: any chatbot conversation with them stops at once (as on the contact record).
        foreach (Conversation::query()->whereIn('contact_id', $optedOut)->where('handler', 'bot')->get() as $running) {
            app(Chatbots::class)->stop($running, 'opted_out');
        }

        $this->audit->record('contact.bulk_updated', null, meta: [
            'contacts' => $contacts->count(),
            'updated' => $updated,
            'skipped' => $skipped,
            'tags_add' => $add,
            'tags_remove' => $remove,
            'field' => $fieldKey,
            'stage' => $stage['id'] ?? ($stage !== null ? 'none' : null),
            'consent' => $consent['state'] ?? null,
            'contact_ids' => $contacts->pluck('id')->take(self::MAX)->all(),
        ]);

        return ['matched' => $contacts->count(), 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @return array{0: ?string, 1: mixed} the custom field key and its normalised value (null: clear it)
     */
    private function field(mixed $field): array
    {
        if (! is_array($field) || ! isset($field['key'])) {
            return [null, null];
        }
        $definition = ContactField::query()->where('key', (string) $field['key'])->first()
            ?? throw ValidationException::withMessages(['field.key' => 'Choose one of your contact fields.']);
        $raw = $field['value'] ?? null;
        $blank = $raw === null || (is_string($raw) && trim($raw) === '') || $raw === [];
        if ($blank) {
            if ((bool) (((array) ($definition->rules ?? []))['required'] ?? false)) {
                throw ValidationException::withMessages(['field.value' => "{$definition->label} is required, so it cannot be cleared."]);
            }

            return [$definition->key, null];
        }

        try {
            $value = $this->fields->values([$definition->key => $raw], false)[$definition->key] ?? null;
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['field.value' => collect($e->errors())->flatten()->first() ?? 'This value does not fit the field.']);
        }

        return [$definition->key, $value];
    }

    /** @return ?array{state: 'opted_in'|'opted_out', note: string} */
    private function consentChange(mixed $consent): ?array
    {
        if (! is_array($consent) || ! isset($consent['state'])) {
            return null;
        }
        $state = (string) $consent['state'];
        $note = trim((string) ($consent['note'] ?? ''));
        if ($state === 'opted_in' && mb_strlen($note) < 10) {
            throw ValidationException::withMessages(['consent.note' => 'Say where these contacts agreed to receive WhatsApp messages from you (at least 10 characters). It is kept in the consent ledger.']);
        }

        return ['state' => $state === 'opted_in' ? 'opted_in' : 'opted_out', 'note' => mb_substr($note !== '' ? $note : 'Bulk edit in Contacts & CRM', 0, 190)];
    }
}
