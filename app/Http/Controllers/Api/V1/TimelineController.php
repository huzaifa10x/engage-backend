<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Crm\Services\EnquiryCards;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Timeline\Enquiries;
use App\Domain\Timeline\Enquiry;
use App\Domain\Timeline\Timeline;
use App\Domain\Timeline\TimelineEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The contact's history (timeline) and enquiries (Contacts). */
final class TimelineController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function timeline(Request $request, Contact $contact): JsonResponse
    {
        $data = $request->validate([
            'group' => ['nullable', Rule::in(array_keys(TimelineEvent::GROUPS))],
            'enquiry_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $page = TimelineEvent::query()->where('contact_id', $contact->id)
            ->when($data['group'] ?? null, fn ($q, string $g) => $q->whereIn('type', TimelineEvent::GROUPS[$g]))
            ->when($data['enquiry_id'] ?? null, fn ($q, string $id) => $q->where('enquiry_id', $id))
            ->orderByDesc('occurred_at')->orderByDesc('id')->cursorPaginate((int) ($data['per_page'] ?? 50));
        $numbers = Enquiry::query()->where('contact_id', $contact->id)->pluck('number', 'id');
        TimelineEvent::markRemovedMessages($page->items());

        return response()->json([
            'data' => collect($page->items())->map(fn (TimelineEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'group' => collect(TimelineEvent::GROUPS)->search(fn (array $types) => in_array($e->type, $types, true)) ?: 'other',
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'actor' => $e->actor,
                'actor_name' => $e->actor_name,
                'enquiry_id' => $e->enquiry_id,
                'enquiry_number' => $e->enquiry_id !== null ? ($numbers[$e->enquiry_id] ?? null) : null,
                'subject' => $e->subject_type !== null ? ['type' => $e->subject_type, 'id' => $e->subject_id] : null,
                'data' => $e->data ?? (object) [],
            ]),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'groups' => array_keys(TimelineEvent::GROUPS)],
        ]);
    }

    public function enquiries(Contact $contact, EnquiryCards $cards): JsonResponse
    {
        $rows = $cards->rows(Enquiry::query()->where('contact_id', $contact->id)->orderByDesc('number')->get());
        foreach ($rows as &$row) {
            $row['current'] = $contact->getAttribute('current_enquiry_id') === $row['id'];
        }

        return response()->json(['data' => $rows]);
    }

    /** A teammate starts a new enquiry by hand: a new buying conversation from an existing customer. */
    public function startEnquiry(Request $request, Contact $contact, Enquiries $enquiries): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);
        $enquiry = $enquiries->manual($contact, $data['note'] ?? null);

        return response()->json(['data' => ['id' => $enquiry->id, 'number' => $enquiry->number]], 201);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => ['quiet_days' => Enquiries::quietDays($this->context->tenant()), 'default_quiet_days' => Enquiries::DEFAULT_QUIET_DAYS]]);
    }

    public function updateSettings(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['quiet_days' => ['required', 'integer', 'min:1', 'max:365']]);
        $tenant = $this->context->tenant();
        $before = Enquiries::quietDays($tenant);
        $settings = (array) ($tenant->settings ?? []);
        $settings['enquiries'] = ['quiet_days' => (int) $data['quiet_days']];
        $tenant->forceFill(['settings' => $settings])->save();
        $audit->record('enquiries.settings_updated', $tenant, before: ['quiet_days' => $before], after: ['quiet_days' => (int) $data['quiet_days']]);

        return $this->settings();
    }
}
