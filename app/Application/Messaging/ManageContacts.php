<?php

declare(strict_types=1);

namespace App\Application\Messaging;

use App\Application\Crm\TagCatalog;
use App\Domain\Audit\AuditLogger;
use App\Domain\Developer\Services\PublicPayload;
use App\Domain\Developer\Services\WebhookDispatcher;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Services\ConsentService;
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

    /** @param array{phone: string, name?: ?string, email?: ?string, attributes?: ?array<string, mixed>, tags?: ?array<int, string>, opted_in?: bool} $data */
    public function create(array $data): Contact
    {
        $waId = Contact::normalizePhone($data['phone']) ?? throw ValidationException::withMessages(['phone' => 'Enter the number in international format, e.g. +971501234567.']);

        $existing = Contact::query()->withTrashed()->where('wa_id', $waId)->first();
        if ($existing !== null && ! $existing->trashed()) {
            throw ValidationException::withMessages(['phone' => 'A contact with this number already exists.']);
        }

        // Contacts are uncapped on every plan (blueprint v2); no entitlement check here.
        if ($existing !== null) {
            $existing->restore();
            $existing->fill($this->attributes($data))->save();
            $contact = $existing;
        } else {
            $contact = Contact::query()->create(['wa_id' => $waId, 'source' => 'manual'] + $this->attributes($data));
        }

        if (($data['opted_in'] ?? false) === true) {
            $this->consent->optIn($contact, 'agent', 'Recorded when the contact was created', membershipId: $this->context->membership()?->id);
        }

        $this->audit->record('contact.created', $contact);
        app(WebhookDispatcher::class)->emit($contact->tenant_id, 'contact.created', PublicPayload::contact($contact->refresh()));

        return $contact;
    }

    /** @param array{name?: ?string, email?: ?string, attributes?: ?array<string, mixed>, tags?: ?array<int, string>} $data */
    public function update(Contact $contact, array $data): Contact
    {
        $before = $contact->only(['name', 'email']);
        $contact->fill($this->attributes($data))->save();
        $this->audit->record('contact.updated', $contact, before: $before, after: $contact->only(['name', 'email']));
        app(WebhookDispatcher::class)->emit($contact->tenant_id, 'contact.updated', PublicPayload::contact($contact));

        return $contact;
    }

    public function delete(Contact $contact): void
    {
        $contact->delete(); // soft: history stays; a new inbound message restores the contact
        $this->audit->record('contact.deleted', $contact);
    }

    /**
     * API `attributes` are stored as `custom_fields` (an `attributes` column would collide with
     * Eloquent's internal property).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $out = array_intersect_key($data, array_flip(['name', 'email']));
        if (array_key_exists('attributes', $data)) {
            $out['custom_fields'] = $data['attributes'];
        }
        if (array_key_exists('tags', $data)) {
            $out['tags'] = $this->tags->ensure((array) $data['tags']);
        }

        return $out;
    }
}
