<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\CrmStage;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The contact record (Inbox & CRM 3.5): the lifecycle stage and lead status worked out from what really
 * happened, the profile fields, the field history (who changed what, when), possible duplicates and how
 * the customer agreed to be contacted.
 */
final class ContactRecord
{
    /** @var array<string, array{stages: Collection<int, object>, resolution: ?object, last_human: ?string, chat?: ?object}> facts loaded for a page of contacts at once */
    private array $facts = [];

    private ?int $firstOpen = null;

    private bool $firstOpenLoaded = false;

    /** Lifecycle stages in order; "Other" sits outside the order (spam or no-action chats with no enquiries). */
    public const LIFECYCLE = ['subscriber', 'lead', 'opportunity', 'customer'];

    public const LIFECYCLE_LABELS = ['subscriber' => 'Subscriber', 'lead' => 'Lead', 'opportunity' => 'Opportunity', 'customer' => 'Customer', 'other' => 'Other'];

    public const LEAD_STATUSES = [
        'new' => 'New', 'attempted' => 'Attempted to contact', 'connected' => 'Connected',
        'open_deal' => 'Open deal', 'bad_timing' => 'Bad timing', 'unqualified' => 'Unqualified',
    ];

    /** How the customer agreed to be contacted, when a teammate starts the chat. */
    public const PERMISSION_VIA = [
        'form' => 'Website or app form', 'call' => 'Phone call', 'in_person' => 'In store or in person', 'email' => 'Email', 'other' => 'Other',
    ];

    /** Fields a teammate can edit on the record, with their labels (the change history uses the same labels). */
    public const EDITABLE = [
        'name' => 'Name', 'email' => 'Email', 'company' => 'Company', 'job_title' => 'Job title', 'city' => 'City',
        'country' => 'Country', 'language' => 'Preferred language', 'lead_status' => 'Lead status',
        'owner_membership_id' => 'Contact owner', 'tags' => 'Tags', 'source_detail' => 'Source detail',
    ];

    /**
     * Lifecycle is worked out from enquiries and never moves backwards: Customer once any enquiry was won,
     * Opportunity once one moved past its first stage, Lead when they wrote or enquired, Subscriber when
     * they only opted in. A chat closed as not a lead / no action with no enquiry makes them Other.
     *
     * @return array{key: string, label: string}
     */
    public function lifecycle(Contact $contact): array
    {
        $stored = $contact->getAttribute('lifecycle');
        if (is_string($stored) && isset(self::LIFECYCLE_LABELS[$stored])) {
            return ['key' => $stored, 'label' => self::LIFECYCLE_LABELS[$stored]];
        }

        return $this->derive($contact);
    }

    /**
     * Works the stage out again after something happened (an enquiry moved or started, a chat was closed,
     * consent changed) and keeps it. It never moves backwards: Customer stays Customer. "Other" only applies
     * while the contact has no enquiry.
     */
    public function refresh(Contact|string|null $contact): void
    {
        if ($contact === null) {
            return;
        }
        $model = $contact instanceof Contact ? $contact : Contact::query()->withTrashed()->find($contact);
        if ($model === null) {
            return;
        }
        unset($this->facts[$model->id]);
        $next = $this->derive($model)['key'];
        $now = $model->getAttribute('lifecycle');
        $rank = array_flip(self::LIFECYCLE);
        if (is_string($now) && isset($rank[$now], $rank[$next]) && $rank[$now] > $rank[$next]) {
            return;
        }
        if (is_string($now) && in_array($now, ['opportunity', 'customer'], true) && $next === 'other') {
            return;
        }
        if ($now !== $next) {
            DB::table('contacts')->where('id', $model->id)->update(['lifecycle' => $next]);
            $model->setAttribute('lifecycle', $next);
        }
    }

    /** @return array{key: string, label: string} */
    private function derive(Contact $contact): array
    {
        $stages = $this->facts($contact)['stages'];
        $first = $this->firstOpen();

        $key = match (true) {
            $stages->contains(fn ($s) => $s->group === CrmStage::WON) => 'customer',
            $stages->contains(fn ($s) => $s->group === CrmStage::OPEN && $s->position !== null && $first !== null && (int) $s->position > (int) $first) => 'opportunity',
            $stages->isEmpty() && $this->closedAsNotLead($contact) => 'other',
            $stages->isNotEmpty() || $contact->last_inbound_at !== null => 'lead',
            $contact->consent_state->value === 'opted_in' => 'subscriber',
            default => 'lead',
        };

        return ['key' => $key, 'label' => self::LIFECYCLE_LABELS[$key]];
    }

    /**
     * Lead status: what a teammate picked wins; otherwise New (no human reply yet), Attempted to contact
     * (we replied, they haven't since), Connected (they replied after us), Open deal. Customers have none.
     *
     * @return array{key: ?string, label: ?string, automatic: bool}
     */
    public function leadStatus(Contact $contact, ?string $lifecycle = null): array
    {
        $lifecycle ??= $this->lifecycle($contact)['key'];
        if ($lifecycle === 'customer') {
            return ['key' => null, 'label' => null, 'automatic' => true];
        }
        if ($contact->lead_status !== null && isset(self::LEAD_STATUSES[$contact->lead_status])) {
            return ['key' => $contact->lead_status, 'label' => self::LEAD_STATUSES[$contact->lead_status], 'automatic' => false];
        }

        $key = match (true) {
            $lifecycle === 'other' => 'unqualified',
            $this->facts($contact)['stages']->contains(fn ($s) => $s->group === CrmStage::OPEN) => 'open_deal',
            default => $this->replyStatus($contact),
        };

        return ['key' => $key, 'label' => self::LEAD_STATUSES[$key], 'automatic' => true];
    }

    /**
     * Writes the changed fields and keeps one history row per field that really changed.
     *
     * @param  array<string, mixed>  $changes  field => new value (only keys in EDITABLE are written)
     * @return list<string> the fields that changed
     */
    public function change(Contact $contact, array $changes, string $source = 'user'): array
    {
        $context = app(TenantContext::class);
        $member = $context->membership();
        $by = $member?->id;
        $byName = $member !== null ? ($member->user()->value('name') ?? null) : null;
        $names = null;
        $changed = [];

        foreach (array_intersect_key($changes, self::EDITABLE) as $field => $value) {
            $old = $contact->getAttribute($field);
            if (is_string($value)) {
                $value = trim($value) === '' ? null : trim($value);
            }
            if ($this->same($old, $value)) {
                continue;
            }
            $contact->setAttribute($field, $value);
            if ($field === 'owner_membership_id') {
                $names ??= DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')->pluck('users.name', 'tenant_memberships.id')->all();
            }
            $changed[$field] = [$this->show($field, $old, $names), $this->show($field, $value, $names)];
        }

        if ($changed === []) {
            return [];
        }
        $contact->save();
        $now = now();
        DB::table('contact_field_changes')->insert(array_map(fn (string $field, array $v) => [
            'id' => (string) Str::uuid7(), 'tenant_id' => $contact->tenant_id, 'contact_id' => $contact->id,
            'field' => $field, 'label' => self::EDITABLE[$field], 'from_value' => $v[0], 'to_value' => $v[1],
            'changed_by_membership_id' => $by, 'changed_by_name' => $byName, 'source' => $source, 'changed_at' => $now,
        ], array_keys($changed), $changed));

        return array_keys($changed);
    }

    /** One history row that isn't a field edit (consent to be contacted, created by …). */
    public function note(Contact $contact, string $field, string $label, ?string $from, ?string $to, string $source = 'user'): void
    {
        $member = app(TenantContext::class)->membership();
        DB::table('contact_field_changes')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $contact->tenant_id, 'contact_id' => $contact->id,
            'field' => $field, 'label' => $label, 'from_value' => $from, 'to_value' => $to,
            'changed_by_membership_id' => $member?->id, 'changed_by_name' => $member !== null ? $member->user()->value('name') : null,
            'source' => $source, 'changed_at' => now(),
        ]);
    }

    /**
     * Records how the customer agreed to be contacted. Kept on the contact and in its history.
     *
     * @param  array{via: string, note?: ?string}  $permission
     */
    public function recordPermission(Contact $contact, array $permission): void
    {
        $member = app(TenantContext::class)->membership();
        $name = $member !== null ? $member->user()->value('name') : null;
        $contact->contact_permission = [
            'via' => $permission['via'], 'note' => $permission['note'] ?? null,
            'by' => $member?->id, 'by_name' => $name, 'at' => now()->toIso8601String(),
        ];
        $contact->save();
        $label = self::PERMISSION_VIA[$permission['via']] ?? 'Other';
        $this->note($contact, 'contact_permission', 'Permission to contact', null, trim($label.(($permission['note'] ?? null) ? ' · '.$permission['note'] : '')));
    }

    /** @return list<array<string, mixed>> the latest field changes, newest first */
    public function changes(Contact $contact, int $limit = 50): array
    {
        return DB::table('contact_field_changes')->where('contact_id', $contact->id)->orderByDesc('changed_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'field' => $r->field, 'label' => $r->label, 'from' => $r->from_value, 'to' => $r->to_value,
                'by' => $r->changed_by_membership_id !== null || $r->changed_by_name !== null ? ['id' => $r->changed_by_membership_id, 'name' => $r->changed_by_name ?? 'Former teammate'] : null,
                'source' => $r->source, 'at' => Carbon::parse($r->changed_at)->toIso8601String(),
            ])->values()->all();
    }

    /**
     * Other contacts that look like the same person: the same phone digits, email or business-scoped
     * user id. Shown above the record so a teammate can decide (merging comes in a later batch).
     *
     * @return list<array{id: string, display_name: string, match: string}>
     */
    public function duplicates(Contact $contact): array
    {
        $digits = $contact->wa_id !== null ? preg_replace('/\D+/', '', $contact->wa_id) : '';
        $email = $contact->email !== null ? mb_strtolower(trim($contact->email)) : '';
        if ($digits === '' && $email === '' && $contact->bsuid === null) {
            return [];
        }

        return Contact::query()->whereKeyNot($contact->id)
            ->where(fn ($q) => $q
                ->when($digits !== '', fn ($q) => $q->orWhereRaw("regexp_replace(coalesce(wa_id, ''), '\\D', '', 'g') = ?", [$digits]))
                ->when($email !== '', fn ($q) => $q->orWhereRaw('lower(email) = ?', [$email]))
                ->when($contact->bsuid !== null, fn ($q) => $q->orWhere('bsuid', $contact->bsuid)))
            ->limit(5)->get()
            ->map(fn (Contact $c) => [
                'id' => $c->id, 'display_name' => $c->displayName(),
                'match' => $digits !== '' && preg_replace('/\D+/', '', (string) $c->wa_id) === $digits ? 'phone'
                    : ($email !== '' && mb_strtolower((string) $c->email) === $email ? 'email' : 'whatsapp_id'),
            ])->values()->all();
    }

    /**
     * Loads what lifecycle and lead status need for many contacts in a few queries (the Contacts list).
     *
     * @param  iterable<Contact>  $contacts
     */
    public function preload(iterable $contacts): void
    {
        $ids = [];
        foreach ($contacts as $c) {
            $ids[] = $c->id;
        }
        $ids = array_values(array_diff($ids, array_keys($this->facts)));
        if ($ids === []) {
            return;
        }
        $stages = DB::table('enquiries')->leftJoin('crm_stages', 'crm_stages.id', '=', 'enquiries.stage_id')
            ->whereIn('enquiries.contact_id', $ids)->get(['enquiries.contact_id', 'enquiries.value', 'crm_stages.group', 'crm_stages.position'])->groupBy('contact_id');
        $chats = DB::table('conversations')->whereIn('contact_id', $ids)->selectRaw('DISTINCT ON (contact_id) contact_id, id, status, reply_state, window_expires_at, snoozed_until, last_message_at')
            ->orderBy('contact_id')->orderByRaw('last_message_at DESC NULLS LAST')->get()->keyBy('contact_id');
        $resolutions = DB::table('conversation_resolutions')->whereIn('contact_id', $ids)
            ->selectRaw('DISTINCT ON (contact_id) contact_id, reason, reopened_at')->orderBy('contact_id')->orderByDesc('resolved_at')->get()->keyBy('contact_id');
        $human = DB::table('messages')->whereIn('contact_id', $ids)->where('direction', 'outbound')->whereIn('origin', ['agent', 'app_echo'])
            ->where('status', '!=', 'failed')->groupBy('contact_id')->selectRaw('contact_id, max(created_at) AS at')->pluck('at', 'contact_id');
        foreach ($ids as $id) {
            $this->facts[$id] = ['stages' => $stages->get($id, collect())->values(), 'resolution' => $resolutions->get($id), 'last_human' => $human->get($id), 'chat' => $chats->get($id)];
        }
    }

    /**
     * Open deals (enquiries in an open stage) with their total value, the last human reply and the
     * contact's latest chat: the Contacts list and the record's Activity fields.
     *
     * @return array{open_deals: array{count: int, value: float}, last_human_reply_at: ?string, chat: ?array<string, mixed>}
     */
    public function summary(Contact $contact): array
    {
        $f = $this->facts($contact);
        $open = $f['stages']->filter(fn ($s) => $s->group === CrmStage::OPEN);
        $chat = $f['chat'] ?? null;

        return [
            'open_deals' => ['count' => $open->count(), 'value' => (float) $open->sum(fn ($s) => (float) ($s->value ?? 0))],
            'last_human_reply_at' => $f['last_human'] !== null ? Carbon::parse($f['last_human'])->toIso8601String() : null,
            'chat' => $chat === null ? null : [
                'id' => $chat->id,
                'state' => $chat->status === 'closed' ? 'resolved' : ($chat->snoozed_until !== null && Carbon::parse($chat->snoozed_until)->isFuture() ? 'snoozed' : ($chat->reply_state === 'needs_reply' ? 'needs_reply' : 'waiting')),
                'window_expires_at' => $chat->window_expires_at !== null ? Carbon::parse($chat->window_expires_at)->toIso8601String() : null,
            ],
        ];
    }

    /** Facts change when a contact is edited, so a single contact is always read fresh. */
    public function forget(string $contactId): void
    {
        unset($this->facts[$contactId]);
    }

    /** @return array{stages: Collection<int, object>, resolution: ?object, last_human: ?string, chat?: ?object} */
    private function facts(Contact $contact): array
    {
        if (! isset($this->facts[$contact->id])) {
            $this->preload([$contact]);
        }

        return $this->facts[$contact->id];
    }

    private function firstOpen(): ?int
    {
        if (! $this->firstOpenLoaded) {
            $min = CrmStage::query()->active()->where('group', CrmStage::OPEN)->min('position');
            $this->firstOpen = $min === null ? null : (int) $min;
            $this->firstOpenLoaded = true;
        }

        return $this->firstOpen;
    }

    private function closedAsNotLead(Contact $contact): bool
    {
        $last = $this->facts($contact)['resolution'];

        return $last !== null && $last->reopened_at === null && in_array($last->reason, ['not_lead', 'no_action'], true);
    }

    private function replyStatus(Contact $contact): string
    {
        $lastHuman = $this->facts($contact)['last_human'];
        if ($lastHuman === null) {
            return 'new';
        }
        $lastIn = $contact->last_inbound_at;

        return $lastIn !== null && $lastIn->gt(Carbon::parse($lastHuman)) ? 'connected' : 'attempted';
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            $a = array_values((array) $a);
            $b = array_values((array) $b);
            sort($a);
            sort($b);
        }

        return $a === $b;
    }

    /** @param array<string, string>|null $names */
    private function show(string $field, mixed $value, ?array $names): ?string
    {
        if ($value === null || $value === [] || $value === '') {
            return null;
        }

        return match ($field) {
            'tags' => implode(', ', (array) $value),
            'lead_status' => self::LEAD_STATUSES[$value] ?? (string) $value,
            'owner_membership_id' => $names[$value] ?? 'Former teammate',
            default => (string) $value,
        };
    }
}
