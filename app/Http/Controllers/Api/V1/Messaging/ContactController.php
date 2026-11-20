<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Application\Messaging\ManageContacts;
use App\Application\WhatsApp\NumberAccess;
use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Crm\Models\Segment;
use App\Domain\Crm\Services\ContactRecord;
use App\Domain\Crm\Services\SegmentQuery;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\ConsentAutoReply;
use App\Domain\Messaging\Services\ConsentService;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ContactResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ContactController extends Controller
{
    public function index(Request $request, SegmentQuery $segments): AnonymousResourceCollection
    {
        // Contacts & CRM 3.5 lists by last activity (a message either way, an edit, or creation), newest first.
        if ($request->input('sort') === 'activity') {
            $per = (int) ($request->integer('per_page') ?: 50);
            $page = max(1, (int) $request->input('cursor', 1));
            $rows = $this->filtered($request, $segments)
                ->with(['syncedFrom', 'stage'])
                ->select('contacts.*')
                ->selectRaw(self::ACTIVITY_SQL.' AS last_activity_at')
                ->orderByDesc('last_activity_at')
                ->orderByDesc('id')
                ->offset(($page - 1) * $per)
                ->limit($per + 1)
                ->get();

            return ContactResource::collection($rows->take($per))
                ->additional(['meta' => ['next_cursor' => $rows->count() > $per ? (string) ($page + 1) : null, 'per_page' => $per]]);
        }
        $contacts = $this->filtered($request, $segments)
            ->with(['syncedFrom', 'stage'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($request->integer('per_page') ?: 50));

        return ContactResource::collection($contacts);
    }

    /**
     * Counts for the Contacts page: how many match the current filters, how many there are in all,
     * and the sources contacts came from (for the Source filter).
     */
    public function count(Request $request, SegmentQuery $segments): JsonResponse
    {
        $sources = Contact::query()->selectRaw('source, count(*) as n')->groupBy('source')->orderByDesc('n')->get()
            ->map(fn (Contact $c) => ['value' => (string) $c->getAttribute('source'), 'count' => (int) $c->getAttribute('n')])
            ->filter(fn (array $s) => $s['value'] !== '')->values();

        return response()->json(['data' => [
            'matching' => $this->filtered($request, $segments)->count(),
            'total' => Contact::query()->count(),
            'sources' => $sources,
        ]]);
    }

    /** @return Builder<Contact> */
    private function filtered(Request $request, SegmentQuery $segments): Builder
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'consent' => ['nullable', Rule::enum(ConsentState::class)],
            'tag' => ['nullable', 'string', 'max:40'],
            'segment_id' => ['nullable', 'uuid'],
            'synced_from' => ['nullable', 'uuid'],   // a phone number id: only contacts synced from that number
            'source' => ['nullable', 'string', 'max:40'],  // how the contact was added: manual, import, inbound …
            'stage_id' => ['nullable', 'string', 'max:36'],  // a stage id, or "none" for contacts without a stage
            'filter' => ['nullable'],                 // {match, rules} (JSON): conditions on any field, custom fields included
            // Contacts & CRM 3.5: the lifecycle chips, the owner filter and "Possible duplicates".
            'lifecycle' => ['nullable', Rule::in(['subscriber', 'lead', 'opportunity', 'customer', 'other'])],
            'owner' => ['nullable', 'string', 'max:36'],       // a membership id, or "none"
            'duplicates' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $segment = isset($data['segment_id']) ? Segment::query()->findOrFail($data['segment_id']) : null;
        $filter = $segments->filter($data['filter'] ?? null);

        return Contact::query()
            ->when($data['stage_id'] ?? null, fn ($q, string $id) => $id === 'none' ? $q->whereNull('stage_id') : $q->where('stage_id', $id))
            ->when($data['synced_from'] ?? null, fn ($q, string $id) => $q->where('synced_from_phone_number_id', $id))
            ->when($data['source'] ?? null, fn ($q, string $source) => $q->where('source', $source))
            ->when($segment, fn ($q) => $segments->apply($q, $segment->match, $segment->rules))
            ->when($filter, fn ($q) => $segments->apply($q, $filter['match'], $filter['rules']))
            ->when($data['tag'] ?? null, fn ($q, string $tag) => $q->whereRaw('tags @> ARRAY[?]::text[]', [$tag]))
            ->when($data['lifecycle'] ?? null, fn ($q, string $l) => $q->whereRaw("coalesce(lifecycle, 'lead') = ?", [$l]))
            ->when($data['owner'] ?? null, fn ($q, string $o) => $o === 'none' ? $q->whereNull('owner_membership_id') : $q->where('owner_membership_id', $o))
            ->when($request->boolean('duplicates'), fn ($q) => $q->whereRaw(self::DUPLICATE_SQL))
            ->when($data['q'] ?? null, function ($q, string $term) {
                $digits = preg_replace('/\D+/', '', $term);
                $q->where(fn ($w) => $w
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('profile_name', 'ilike', "%{$term}%")
                    ->orWhere('username', 'ilike', "%{$term}%")
                    ->orWhere('email', 'ilike', "%{$term}%")
                    ->orWhere('company', 'ilike', "%{$term}%")
                    // The engageX ID shown on the record (EX- and the end of the contact's id).
                    ->when(preg_match('/^(?:EX-)?([0-9a-f]{6,12})$/i', trim($term), $m) === 1, fn ($w) => $w->orWhereRaw('id::text ILIKE ?', ['%'.strtolower($m[1])]))
                    ->when(ltrim($digits, '0') !== '', fn ($w) => $w->orWhere('wa_id', 'like', '%'.ltrim($digits, '0').'%')));
            })
            ->when($data['consent'] ?? null, fn ($q, string $c) => $q->where('consent_state', $c));
    }

    /** The latest of: the contact's last chat message, an edit to the record, its creation. */
    private const ACTIVITY_SQL = 'greatest(contacts.updated_at, contacts.created_at, '
        .'coalesce((SELECT max(cv.last_message_at) FROM conversations cv WHERE cv.contact_id = contacts.id), contacts.created_at))';

    /** Another contact has the same phone digits, email or WhatsApp user id. */
    private const DUPLICATE_SQL = 'EXISTS (SELECT 1 FROM contacts o WHERE o.id <> contacts.id AND o.tenant_id = contacts.tenant_id AND o.deleted_at IS NULL AND ('
        ."(contacts.wa_id IS NOT NULL AND regexp_replace(coalesce(o.wa_id, ''), '\\D', '', 'g') = regexp_replace(contacts.wa_id, '\\D', '', 'g'))"
        ." OR (contacts.email IS NOT NULL AND contacts.email <> '' AND lower(o.email) = lower(contacts.email))"
        .' OR (contacts.bsuid IS NOT NULL AND o.bsuid = contacts.bsuid)))';

    /**
     * The lifecycle chips of the Contacts list (3.5): how many contacts are at each stage, and how many
     * pairs look like the same person.
     */
    public function funnel(): JsonResponse
    {
        $by = Contact::query()->selectRaw("coalesce(lifecycle, 'lead') AS l, count(*) AS n")->groupBy('l')->pluck('n', 'l');
        $dupes = Contact::query()->whereRaw(self::DUPLICATE_SQL)->count();

        return response()->json(['data' => [
            'all' => (int) $by->sum(),
            'subscriber' => (int) ($by['subscriber'] ?? 0), 'lead' => (int) ($by['lead'] ?? 0), 'opportunity' => (int) ($by['opportunity'] ?? 0),
            'customer' => (int) ($by['customer'] ?? 0), 'other' => (int) ($by['other'] ?? 0),
            'duplicates' => $dupes,
        ]]);
    }

    public function store(Request $request, ManageContacts $contacts): JsonResponse
    {
        $data = $request->validate([
            // A WhatsApp number or an email (3.5: a CRM contact can wait for its first chat).
            'phone' => ['required_without:email', 'nullable', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:190'],
            'email' => ['required_without:phone', 'nullable', 'email:rfc', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'source_detail' => ['nullable', 'string', 'max:80'],
            'owner_membership_id' => ['nullable', 'uuid'],
            'attributes' => ['nullable', 'array', 'max:50'],
            'tags' => ['nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'max:40'],
            'opted_in' => ['sometimes', 'boolean'],
        ]);

        // 201 also when a previously deleted contact is restored: the client created it again.
        return ContactResource::make($contacts->create($data))->response()->setStatusCode(201);
    }

    public function show(Contact $contact): ContactResource
    {
        return ContactResource::make($contact->load('syncedFrom'));
    }

    public function update(Request $request, Contact $contact, ManageContacts $contacts, ContactRecord $record): ContactResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'attributes' => ['sometimes', 'nullable', 'array', 'max:50'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'max:40'],
            'company' => ['sometimes', 'nullable', 'string', 'max:190'],
            'source_detail' => ['sometimes', 'nullable', 'string', 'max:80'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'country' => ['sometimes', 'nullable', 'string', 'max:80'],
            'language' => ['sometimes', 'nullable', 'string', 'max:40'],
            'lead_status' => ['sometimes', 'nullable', Rule::in(array_keys(ContactRecord::LEAD_STATUSES))],
            'owner_membership_id' => ['sometimes', 'nullable', 'uuid'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);
        if (array_key_exists('phone', $data)) {
            $this->changePhone($contact, $data['phone'], array_key_exists('email', $data) ? $data['email'] : $contact->email, $record);
            unset($data['phone']);
        }
        if (array_key_exists('email', $data) && ($data['email'] === null || trim((string) $data['email']) === '') && $contact->wa_id === null && $contact->bsuid === null) {
            throw ValidationException::withMessages(['email' => 'A contact needs a phone, a WhatsApp ID or an email.']);
        }
        if (array_key_exists('lead_status', $data) && $data['lead_status'] !== null && $record->lifecycle($contact)['key'] === 'customer') {
            throw ValidationException::withMessages(['lead_status' => "Customers don't have a lead status."]);
        }
        if (($data['owner_membership_id'] ?? null) !== null && ! TenantMembership::query()->active()->whereKey($data['owner_membership_id'])->exists()) {
            throw ValidationException::withMessages(['owner_membership_id' => 'Pick someone on the team.']);
        }

        return ContactResource::make($contacts->update($contact, $data));
    }

    /**
     * The WhatsApp phone can be typed in only while the contact has no chat: once WhatsApp gave the number,
     * it is read-only. Another contact with the same number blocks it (merge instead).
     */
    private function changePhone(Contact $contact, ?string $phone, ?string $email, ContactRecord $record): void
    {
        if ($contact->wa_id !== null && Conversation::query()->where('contact_id', $contact->id)->exists()) {
            throw ValidationException::withMessages(['phone' => 'Comes from WhatsApp for this chat. Read-only.']);
        }
        $waId = $phone === null || trim($phone) === '' ? null : Contact::checkedPhone($phone);
        if ($waId === null && ($email === null || trim($email) === '') && $contact->bsuid === null) {
            throw ValidationException::withMessages(['phone' => 'A contact needs a phone, a WhatsApp ID or an email.']);
        }
        if ($waId !== null && ($other = Contact::query()->where('wa_id', $waId)->whereKeyNot($contact->id)->first()) !== null) {
            throw ValidationException::withMessages(['phone' => $other->displayName().' ('.self::engageId($other->id).') already has this number. Merge instead.']);
        }
        if ($waId === $contact->wa_id) {
            return;
        }
        $record->note($contact, 'phone', 'WhatsApp phone', $contact->wa_id !== null ? '+'.$contact->wa_id : null, $waId !== null ? '+'.$waId : null);
        $contact->forceFill(['wa_id' => $waId])->save();
    }

    /** The short engageX ID people see: "EX-" and the last 6 characters of the contact id. */
    public static function engageId(string $id): string
    {
        return 'EX-'.mb_strtoupper(mb_substr(str_replace('-', '', $id), -6));
    }

    /** The record's field history: who changed what, newest first. */
    public function changes(Contact $contact, ContactRecord $record): JsonResponse
    {
        return response()->json(['data' => $record->changes($contact)]);
    }

    /**
     * The contact's chats the current teammate may see, latest first: the record's Open chat button and
     * its WhatsApp window ring.
     */
    public function conversations(Contact $contact, TenantContext $context, NumberAccess $access): JsonResponse
    {
        $membership = $context->membership();
        abort_unless($membership instanceof TenantMembership, 403);
        $query = Conversation::query()->where('contact_id', $contact->id);
        $access->scopeChats($query, $membership);

        return response()->json(['data' => $query->orderByDesc('last_message_at')->limit(10)->get()->map(fn (Conversation $c) => [
            'id' => $c->id, 'phone_number_id' => $c->phone_number_id, 'status' => $c->status->value,
            'window_expires_at' => $c->window_expires_at?->toIso8601String(), 'window_open' => $c->isWindowOpen(),
            'assigned_membership_id' => $c->assigned_membership_id, 'last_message_at' => $c->last_message_at?->toIso8601String(),
        ])->values()->all()]);
    }

    /** Other contacts with the same number, email or WhatsApp user id. */
    public function duplicates(Contact $contact, ContactRecord $record): JsonResponse
    {
        return response()->json(['data' => $record->duplicates($contact)]);
    }

    /** Record consent given or withdrawn outside WhatsApp (form, call, in person). */
    /**
     * A teammate records the customer's consent (they asked in the chat, on a form, in the shop …).
     * The state change is the record; with `notify` (default) the customer also gets the workspace's
     * opt-in / opt-out confirmation when a 24-hour window is open. `meta.confirmation` says what
     * happened to that message, so the inbox can tell the teammate.
     */
    /** The contact's consent history, newest first: what, how (source, channel), the exact words shown, and when. */
    public function consentHistory(Contact $contact, ConsentService $consent): JsonResponse
    {
        return response()->json(['data' => $consent->history($contact)]);
    }

    /** How a teammate obtained the consent they record (stored first in the ledger's detail). */
    public const CONSENT_METHODS = [
        'phone_call' => 'Phone call', 'in_person' => 'In person / in the shop', 'form' => 'Website or paper form', 'email' => 'Email', 'other' => 'Other',
    ];

    public function consent(Request $request, Contact $contact, ConsentService $consent, TenantContext $context, ConsentAutoReply $replies): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', Rule::in([ConsentState::OptedIn->value, ConsentState::OptedOut->value])],
            // How it was obtained: required, our consent rules depend on it (3.3, A1). "Other" needs a description.
            'method' => ['required', Rule::in(array_keys(self::CONSENT_METHODS))],
            'note' => [Rule::requiredIf(fn () => $request->input('method') === 'other'), 'nullable', 'string', 'min:'.($request->input('method') === 'other' ? 5 : 0), 'max:150'],
            'notify' => ['sometimes', 'boolean'],
            'conversation_id' => ['nullable', 'uuid'],
        ], [
            'method.required' => 'Say how the consent was obtained.',
            'note.required' => 'Describe how the consent was obtained.',
            'note.min' => 'Describe how the consent was obtained.',
        ]);
        $in = $data['state'] === ConsentState::OptedIn->value;
        $note = trim(self::CONSENT_METHODS[$data['method']].(isset($data['note']) && trim($data['note']) !== '' ? ' · '.trim($data['note']) : ''));
        // The customer opted out themselves (STOP, the Unsubscribe button, WhatsApp's own setting): only
        // they can opt back in (START or Subscribe). A teammate cannot override their own choice.
        if ($in && $contact->consent_state === ConsentState::OptedOut && ($by = $consent->customerOptOut($contact)) !== null) {
            throw ValidationException::withMessages(['state' => "This customer opted out themselves ({$by}). Only they can opt back in: ask for marketing consent in the chat, or they can reply START."]);
        }

        $changed = $in
            ? $consent->optIn($contact, 'agent', $note, membershipId: $context->membership()?->id)
            : $consent->optOut($contact, 'agent', $note, membershipId: $context->membership()?->id);

        // An opt-out ends any chatbot conversation with this contact at once (as a typed STOP does).
        if ($changed && ! $in) {
            foreach (Conversation::query()->where('contact_id', $contact->id)->where('handler', 'bot')->get() as $running) {
                app(Chatbots::class)->stop($running, 'opted_out');
            }
        }

        $confirmation = null;
        // A change recorded by the team is not a reply to the customer: nothing is sent unless asked.
        if ($changed && ($data['notify'] ?? false)) {
            $conversation = isset($data['conversation_id']) ? Conversation::query()->find($data['conversation_id']) : null; // tenant-scoped
            $confirmation = $replies->confirmRecorded($contact, $in ? 'opt_in' : 'opt_out', $conversation);
        }

        return ContactResource::make($contact->refresh())->additional(['meta' => [
            'changed' => $changed,
            'confirmation' => $confirmation,
        ]])->response();
    }
}
