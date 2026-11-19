<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\CrmStage;
use App\Domain\Crm\Models\NextStep;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Timeline\Enquiries;
use App\Domain\Timeline\Enquiry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How an enquiry is shown everywhere (the chat's Enquiry card, the contact, the deals board, the
 * API): its stage and group, its value, verified payments kept apart from a booking marked by staff,
 * the reference a Won was marked with, and the next open step.
 */
final class EnquiryCards
{
    /**
     * @param  Collection<int, Enquiry>  $list
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $list, bool $withContact = false): array
    {
        if ($list->isEmpty()) {
            return [];
        }
        $ids = $list->pluck('id')->all();
        $names = DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')->pluck('users.name', 'tenant_memberships.id')->all();
        $stages = CrmStage::query()->whereIn('id', $list->pluck('stage_id')->filter())->get()->keyBy('id');
        $orders = DB::table('commerce_orders')->whereIn('enquiry_id', $ids)
            ->get(['id', 'enquiry_id', 'number', 'status', 'total', 'currency', 'paid', 'paid_at', 'store_name'])->groupBy('enquiry_id');
        $counts = DB::table('timeline_events')->whereIn('enquiry_id', $ids)->whereIn('type', ['message.received', 'message.sent'])
            ->groupBy('enquiry_id')->selectRaw('enquiry_id, count(*) AS total')->pluck('total', 'enquiry_id');
        $steps = NextStep::query()->open()->whereIn('enquiry_id', $ids)->orderBy('due_at')->get()->groupBy('enquiry_id');
        // The last move into the Won group: its reference (or "Verified payment").
        $wonIds = CrmStage::query()->where('group', CrmStage::WON)->pluck('id');
        $won = DB::table('contact_stage_events')->whereIn('enquiry_id', $ids)->whereIn('to_stage_id', $wonIds)
            ->orderByDesc('changed_at')->get(['enquiry_id', 'reason', 'changed_by_membership_id', 'changed_at', 'source'])->unique('enquiry_id')->keyBy('enquiry_id');
        $contacts = $withContact ? Contact::query()->withTrashed()->whereIn('id', $list->pluck('contact_id')->unique())->get()->keyBy('id') : collect();

        $rows = [];
        foreach ($list as $e) {
            $payments = [];
            foreach ($orders->get($e->id, collect()) as $o) {
                $payments[] = ['type' => 'order', 'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'total' => (float) $o->total,
                    'currency' => $o->currency, 'paid' => (bool) $o->paid, 'paid_at' => $o->paid_at, 'by' => $o->store_name];
            }
            $paid = array_values(array_filter($payments, fn (array $p) => $p['paid']));
            $stage = $e->stage_id !== null ? ($stages[$e->stage_id] ?? null) : null;
            $group = $stage?->group ?? CrmStage::OPEN;
            $next = $steps->get($e->id)?->first();
            $w = $won[$e->id] ?? null;
            $row = [
                'id' => $e->id,
                'contact_id' => $e->contact_id,
                'number' => $e->number,
                'title' => $e->title,
                'value' => $e->value !== null ? (float) $e->value : null,
                'value_period' => $e->value_period,
                'expected_close_on' => $e->expected_close_on?->toDateString(),
                'details_version' => $e->details_version,
                'started_at' => $e->started_at->toIso8601String(),
                'started_by' => $e->started_by,
                'start_reason' => $e->start_reason,
                'imported' => $e->isImported(),
                'source' => $e->source,
                'source_label' => Enquiries::label(['source' => $e->source, 'detail' => (array) $e->source_detail]),
                'source_confidence' => $e->source_confidence,
                'confidence_label' => Enquiry::CONFIDENCE_LABELS[$e->source_confidence] ?? 'Unknown',
                'owner' => $e->owner_membership_id !== null ? ['id' => $e->owner_membership_id, 'name' => $names[$e->owner_membership_id] ?? 'Former teammate'] : null,
                'stage' => $stage !== null ? ['id' => $stage->id, 'name' => $stage->name, 'group' => $group, 'color' => $stage->color] : null,
                'group' => $group,
                'open' => $group === CrmStage::OPEN,
                // Sent back with a stage change, so two people can't overwrite each other (item 4).
                'stage_version' => $e->stage_version,
                'lost_reason' => $e->lost_reason,
                // Money: verified payments only. A Won marked by staff shows its reference, never as paid.
                'verified_paid' => $paid !== [],
                'paid_total' => array_sum(array_map(fn (array $p) => $p['currency'] === 'AED' ? $p['total'] : 0, $paid)),
                'won' => $group === CrmStage::WON && $w !== null ? [
                    'reference' => $w->reason,
                    'verified' => in_array($w->source, ['payment', 'booking'], true) || $paid !== [],
                    'by' => $w->changed_by_membership_id !== null ? ($names[$w->changed_by_membership_id] ?? 'A teammate') : null,
                    'at' => Carbon::parse($w->changed_at)->toIso8601String(),
                ] : null,
                'next_step' => $next?->summary($names),
                'last_activity_at' => $e->last_activity_at?->toIso8601String(),
                'updated_at' => ($e->details_updated_at ?? $e->updated_at)?->toIso8601String(),
                'updated_by' => $e->getAttribute('details_updated_by') !== null ? ($names[$e->getAttribute('details_updated_by')] ?? null) : null,
                'messages' => (int) ($counts[$e->id] ?? 0),
                'bookings' => [],
                'payments' => $payments,
            ];
            if ($withContact) {
                $c = $contacts[$e->contact_id] ?? null;
                $row['contact'] = $c !== null ? ['id' => $c->id, 'name' => $c->displayName(), 'deleted' => $c->trashed()] : null;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
