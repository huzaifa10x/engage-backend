<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Timeline\Enquiries;
use App\Domain\Timeline\Enquiry;
use App\Domain\Timeline\Timeline;
use App\Domain\Timeline\TimelineEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function enquiries(Contact $contact): JsonResponse
    {
        $list = Enquiry::query()->where('contact_id', $contact->id)->orderByDesc('number')->get();
        $owners = TenantMembership::query()->with('user')->whereIn('id', $list->pluck('owner_membership_id')->filter())->get()->keyBy('id');
        $stages = CrmStage::query()->whereIn('id', $list->pluck('stage_id')->filter())->get()->keyBy('id');
        $orders = DB::table('commerce_orders')->whereIn('enquiry_id', $list->modelKeys())
            ->get(['id', 'enquiry_id', 'number', 'status', 'total', 'currency', 'paid'])->groupBy('enquiry_id');
        $counts = DB::table('timeline_events')->whereIn('enquiry_id', $list->modelKeys())->whereIn('type', ['message.received', 'message.sent'])
            ->groupBy('enquiry_id')->selectRaw('enquiry_id, count(*) AS total')->pluck('total', 'enquiry_id');

        $rows = [];
        foreach ($list as $e) {
            $payments = [];
            foreach ($orders->get($e->id, collect()) as $o) {
                $payments[] = ['type' => 'order', 'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'total' => (float) $o->total, 'currency' => $o->currency, 'paid' => (bool) $o->paid];
            }
            $rows[] = [
                'id' => $e->id,
                'number' => $e->number,
                'current' => $contact->getAttribute('current_enquiry_id') === $e->id,
                'started_at' => $e->started_at->toIso8601String(),
                'started_by' => $e->started_by,
                'start_reason' => $e->start_reason,
                'imported' => $e->isImported(),
                'source' => $e->source,
                'source_label' => Enquiries::label(['source' => $e->source, 'detail' => (array) $e->source_detail]),
                'source_confidence' => $e->source_confidence,
                'confidence_label' => Enquiry::CONFIDENCE_LABELS[$e->source_confidence],
                'owner' => $e->owner_membership_id !== null ? ['id' => $e->owner_membership_id, 'name' => $owners[$e->owner_membership_id]->user->name ?? 'Former teammate'] : null,
                'stage' => $e->stage_id !== null ? ['id' => $e->stage_id, 'name' => $stages[$e->stage_id]->name ?? null, 'group' => $stages[$e->stage_id]->group ?? CrmStage::OPEN] : null,
                // Sent back with a stage change, so two people can't overwrite each other (item 4).
                'stage_version' => $e->stage_version,
                'lost_reason' => $e->lost_reason,
                'verified_paid' => collect($payments)->contains(fn (array $p) => $p['paid']),
                'last_activity_at' => $e->last_activity_at?->toIso8601String(),
                'messages' => (int) ($counts[$e->id] ?? 0),
                'bookings' => [],
                'payments' => $payments,
            ];
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
