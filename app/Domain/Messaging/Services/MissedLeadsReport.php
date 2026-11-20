<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Crm\Models\CrmStage;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Timeline\Enquiry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The weekly missed-leads report (3.3, A3, the pilot kit), counted per enquiry, Monday to Sunday in
 * the workspace's time zone:
 *
 *  - enquiries in; answered within the limit; waited over the limit (listed); still within the limit;
 *  - reply time per staff member (median, business hours, every chat);
 *  - enquiries by how sure the source is (Verified / Partial / Unknown) and by ad;
 *  - AED at stake: enquiries that waited over × the average booking value;
 *  - AED protected: verified payments only (a paid store order linked to the week's enquiries).
 *    A booking marked by staff is never counted as money;
 *  - the week-zero audit baseline the Owner entered by hand.
 *
 * Imported history is left out: the team could not have answered it in Engage.
 */
final class MissedLeadsReport
{
    /** @return array<string, mixed> */
    public function build(Tenant $tenant, int $weeksAgo = 0, ?Carbon $now = null): array
    {
        $now ??= now();
        $tz = BusinessHours::timezone($tenant);
        $from = $now->copy()->setTimezone($tz)->startOfWeek(Carbon::MONDAY)->subWeeks($weeksAgo);
        $to = $from->copy()->addWeek();
        $until = $to->lt($now) ? $to : $now;
        $promise = ReplyPromise::settings($tenant);
        $limit = $promise['limit_minutes'] * 60;

        $enquiries = Enquiry::query()->live()->where('started_by', 'customer')->whereBetween('started_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->orderBy('started_at')->get();
        $contacts = Contact::query()->withTrashed()->whereIn('id', $enquiries->pluck('contact_id')->unique())->get()->keyBy('id');
        $owners = $this->names();
        $conversations = DB::table('conversations')->whereIn('id', $enquiries->pluck('conversation_id')->filter()->unique())
            ->get(['id', 'phone_number_id', 'assigned_membership_id', 'reply_state', 'needs_reply_since'])->keyBy('id');

        $rows = $enquiries->map(function (Enquiry $e) use ($conversations, $tenant, $until, $limit) {
            $c = $e->conversation_id !== null ? ($conversations[$e->conversation_id] ?? null) : null;
            // The first person's reply to this enquiry: the first wait that started with it.
            $first = $c === null ? null : DB::table('inbox_response_times')->where('conversation_id', $c->id)->where('kind', 'first_response')
                ->where('started_at', '>=', $e->started_at->copy()->subMinutes(2))->orderBy('started_at')->first(['business_seconds', 'membership_id', 'ended_at']);
            if ($first !== null) {
                $waited = (int) $first->business_seconds;
                $outcome = $waited <= $limit ? 'in_time' : 'over';
            } else {
                $waited = BusinessHours::seconds($tenant, $c?->phone_number_id, $e->started_at, $until);
                $outcome = $waited > $limit ? 'over' : 'pending';
            }

            return ['enquiry' => $e, 'conversation' => $c, 'waited' => $waited, 'answered' => $first !== null, 'outcome' => $outcome];
        });

        $over = $rows->where('outcome', 'over')->values();
        $staff = DB::table('inbox_response_times')->where('kind', 'first_response')->whereNotNull('membership_id')
            ->whereBetween('ended_at', [$from->copy()->utc(), $to->copy()->utc()])->get(['membership_id', 'business_seconds'])
            ->groupBy('membership_id')->map(function ($list, $id) use ($owners, $limit) {
                $s = $list->pluck('business_seconds')->map(fn ($v) => (int) $v)->sort()->values();

                return ['membership_id' => $id, 'name' => $owners[$id] ?? 'A teammate', 'replies' => $s->count(),
                    'median_seconds' => (int) $s->median(), 'within_limit' => $s->filter(fn ($v) => $v <= $limit)->count()];
            })->sortByDesc('replies')->values();

        $ids = $enquiries->pluck('id');
        $paid = DB::table('commerce_orders')->whereIn('enquiry_id', $ids)->where('paid', true)->get(['enquiry_id', 'total', 'currency']);
        $protected = (float) $paid->where('currency', 'AED')->sum(fn ($o) => (float) $o->total);
        $wonOrBooked = CrmStage::query()->where(fn ($q) => $q->where('group', CrmStage::WON)->orWhereRaw('lower(name) = ?', ['booked']))->pluck('id');
        $bookedNoPay = $enquiries->filter(fn (Enquiry $e) => $e->stage_id !== null && $wonOrBooked->contains($e->stage_id) && ! $paid->contains('enquiry_id', $e->id))->count();

        $byAd = $enquiries->groupBy(fn (Enquiry $e) => $e->source === 'ad' ? (string) ($e->source_detail['headline'] ?? $e->source_detail['ad_id'] ?? 'Click-to-WhatsApp ad') : Enquiry::SOURCE_LABELS[$e->source] ?? 'Direct')
            ->map(fn ($list, $label) => [
                'label' => $label,
                'enquiries' => $list->count(),
                'verified' => $list->where('source_confidence', 'verified')->count(),
                'partial' => $list->where('source_confidence', 'partial')->count(),
                'unknown' => $list->where('source_confidence', 'unknown')->count(),
            ])->sortByDesc('enquiries')->values();

        $value = $promise['avg_booking_value'];

        return [
            'week' => ['from' => $from->toDateString(), 'to' => $to->copy()->subDay()->toDateString(), 'weeks_ago' => $weeksAgo, 'complete' => $to->lte($now)],
            'limit_minutes' => $promise['limit_minutes'],
            'enquiries' => $rows->count(),
            'in_time' => $rows->where('outcome', 'in_time')->count(),
            'over' => $over->count(),
            'pending' => $rows->where('outcome', 'pending')->count(),
            'waited_over' => $over->map(fn ($r) => [
                'enquiry_id' => $r['enquiry']->id,
                'enquiry_number' => $r['enquiry']->number,
                'title' => $r['enquiry']->getAttribute('title'),
                'contact_id' => $r['enquiry']->contact_id,
                'customer' => ($contacts[$r['enquiry']->contact_id] ?? null)?->displayName() ?? 'A customer',
                'conversation_id' => $r['conversation']?->id,
                'started_at' => $r['enquiry']->started_at->toIso8601String(),
                'waited_seconds' => $r['waited'],
                'answered' => $r['answered'],
                'owner' => ($id = $r['conversation']?->assigned_membership_id) !== null ? ($owners[$id] ?? null) : null,
            ])->all(),
            'staff' => $staff->all(),
            'by_confidence' => collect(['verified', 'partial', 'unknown'])->map(fn ($k) => ['key' => $k, 'label' => Enquiry::CONFIDENCE_LABELS[$k], 'enquiries' => $enquiries->where('source_confidence', $k)->count()])->all(),
            'by_ad' => $byAd->all(),
            'avg_booking_value' => $value,
            'aed_at_stake' => $value !== null ? $over->count() * $value : null,
            'aed_protected' => round($protected, 2),
            'verified_payments' => $paid->count(),
            'booked_without_payment' => $bookedNoPay,
            'baseline' => $promise['baseline'],
        ];
    }

    /** @return array<string, string> membership id → name */
    private function names(): array
    {
        return DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')->pluck('users.name', 'tenant_memberships.id')->all();
    }
}
