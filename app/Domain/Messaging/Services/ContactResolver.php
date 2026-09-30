<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Models\Contact;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Finds or creates the contact for an identity inside the current tenant. Phone match wins,
 * then BSUID; missing identifiers are back-filled so later webhooks that carry only one of them
 * still land on the same contact. Soft-deleted contacts are restored — a customer who writes
 * again must never be dropped.
 */
final class ContactResolver
{
    public function resolve(ContactIdentity $identity, string $source): ?Contact
    {
        if ($identity->isEmpty()) {
            return null;
        }

        $contact = $this->find($identity);

        if ($contact === null) {
            try {
                // Savepoint: a unique violation must not abort the surrounding transaction.
                $contact = DB::transaction(fn () => Contact::query()->create([
                    'wa_id' => $identity->waId,
                    'bsuid' => $identity->bsuid,
                    'parent_bsuid' => $identity->parentBsuid,
                    'username' => $identity->username,
                    'profile_name' => $identity->profileName,
                    'source' => $source,
                ]));

                return $contact;
            } catch (QueryException $e) {
                // Concurrent webhook created it first (unique wa_id / bsuid) — use theirs.
                if ($e->getCode() !== '23505' || ($contact = $this->find($identity)) === null) {
                    throw $e;
                }
            }
        }

        $this->enrich($contact, $identity);

        return $contact;
    }

    public function find(ContactIdentity $identity): ?Contact
    {
        $query = Contact::query()->withTrashed();

        return ($identity->waId !== null ? (clone $query)->where('wa_id', $identity->waId)->first() : null)
            ?? ($identity->bsuid !== null ? (clone $query)->where('bsuid', $identity->bsuid)->first() : null);
    }

    private function enrich(Contact $contact, ContactIdentity $identity): void
    {
        if ($contact->trashed()) {
            $contact->restore();
        }

        $updates = array_filter([
            'wa_id' => $contact->wa_id === null ? $identity->waId : null,
            'bsuid' => $contact->bsuid === null && $identity->bsuid !== null && ! $this->bsuidTaken($identity->bsuid, $contact->id) ? $identity->bsuid : null,
            'parent_bsuid' => $identity->parentBsuid !== $contact->parent_bsuid ? $identity->parentBsuid : null,
            'username' => $identity->username !== $contact->username ? $identity->username : null,
            'profile_name' => $identity->profileName !== $contact->profile_name ? $identity->profileName : null,
        ], fn ($v) => $v !== null);

        if ($updates !== []) {
            $contact->forceFill($updates)->save();
        }
    }

    private function bsuidTaken(string $bsuid, string $exceptId): bool
    {
        return Contact::query()->withTrashed()->where('bsuid', $bsuid)->whereKeyNot($exceptId)->exists();
    }
}
