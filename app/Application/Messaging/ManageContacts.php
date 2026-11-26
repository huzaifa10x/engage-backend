<?php

declare(strict_types=1);

namespace App\Application\Messaging;

use App\Application\Crm\TagCatalog;
use App\Domain\Audit\AuditLogger;
use App\Domain\Crm\Services\ContactRecord;
use App\Domain\Crm\Services\CustomFields;
use App\Domain\Developer\Services\PublicPayload;
use App\Domain\Developer\Services\WebhookDispatcher;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Services\ConsentService;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class ManageContacts
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ConsentService $consent,
        private readonly AuditLogger $audit,
        private readonly TagCatalog $tags,
    ) {}

    /** @param array{phone?: ?string, name?: ?string, email?: ?string, attributes?: ?array<string, mixed>, tags?: ?array<int, string>, opted_in?: bool, owner_membership_id?: ?string, source_detail?: ?string} $data */
    public function create(array $data): Contact
    {
        $waId = ($data['phone'] ?? null) !== null && trim((string) $data['phone']) !== '' ? Contact::checkedPhone($data['phone']) : null;

        $existing = $waId !== null ? Contact::query()->withTrashed()->where('wa_id', $waId)->first() : null;
        if ($existing !== null && ! $existing->trashed()) {
            throw ValidationException::withMessages(['phone' => 'Already in Engage as '.$existing->displayName().'.']);
        }
        $email = isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : '';
        if ($email !== '' && ($same = Contact::query()->whereRaw('lower(email) = ?', [$email])->first()) !== null) {
            throw ValidationException::withMessages(['email' => 'Already in Engage as '.$same->displayName().'.']);
        }
        if (($data['owner_membership_id'] ?? null) !== null && ! TenantMembership::query()->active()->whereKey($data['owner_membership_id'])->exists()) {
            throw ValidationException::withMessages(['owner_membership_id' => 'Pick someone on the team.']);
        }

        // Required custom fields apply to new contacts too, even when no attributes were sent.
        $data['attributes'] ??= [];

        // Contacts are uncapped on every plan (blueprint v2); no entitlement check here.
        if ($existing !== null) {
            $existing->restore();
            $existing->fill($this->attributes($data))->save();
            $contact = $existing;
        } else {
            $contact = Contact::query()->create(['wa_id' => $waId, 'source' => 'manual'] + $this->attributes($data)
                + array_filter(['source_detail' => $data['source_detail'] ?? null, 'owner_membership_id' => $data['owner_membership_id'] ?? null]));
        }

        if (($data['opted_in'] ?? false) === true) {
            $this->consent->optIn($contact, 'agent', 'Recorded when the contact was created', membershipId: $this->context->membership()?->id);
        }

        $this->audit->record('contact.created', $contact);
        app(ContactRecord::class)->refresh($contact);

        return $contact;
    }

    /**
     * @param  array{name?: ?string, email?: ?string, attributes?: ?array<string, mixed>, tags?: ?array<int, string>}  $data
     * @param  bool  $partial  true when only some fields are written (a chatbot saving one answer):
     *                         fields marked required elsewhere are not demanded then.
     */
    public function update(Contact $contact, array $data, bool $partial = false): Contact
    {
        $before = $contact->only(['name', 'email']);
        $attributes = $this->attributes($data, ! $partial);
        // Record fields are written through ContactRecord, which keeps who changed what (3.5).
        $record = array_intersect_key($attributes + $data, ContactRecord::EDITABLE);
        $contact->fill(array_diff_key($attributes, $record));
        app(ContactRecord::class)->change($contact, $record, $partial ? 'system' : 'user');
        $contact->save();
        $this->audit->record('contact.updated', $contact, before: $before, after: $contact->only(['name', 'email']));
        app(WebhookDispatcher::class)->emit($contact->tenant_id, 'contact.updated', PublicPayload::contact($contact));

        return $contact;
    }

    /** Removes the contact's data (batch 6, item 14): see EraseContact. */
    public function delete(Contact $contact): void
    {
        $this->audit->record('contact.deleted', $contact);
        app(EraseContact::class)->erase($contact);
    }

    /**
     * API `attributes` are stored as `custom_fields` (an `attributes` column would collide with
     * Eloquent's internal property).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, bool $checkRequired = true): array
    {
        $out = array_intersect_key($data, array_flip(['name', 'email', 'company', 'job_title', 'city', 'country', 'language']));
        if (array_key_exists('attributes', $data)) {
            // Values of the workspace's custom fields are checked against their type and rules.
            $out['custom_fields'] = app(CustomFields::class)->values((array) ($data['attributes'] ?? []), $checkRequired) ?: null;
        }
        if (array_key_exists('tags', $data)) {
            $out['tags'] = $this->tags->ensure((array) $data['tags']);
        }

        return $out;
    }
}
