<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Crm;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Audit\AuditLogger;
use App\Domain\Crm\Exceptions\StageConflict;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Crm\Models\NextStep;
use App\Domain\Crm\Services\EnquiryCards;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Timeline\Enquiry;
use App\Domain\Timeline\Timeline;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Contacts & CRM › Enquiries (3.3, B1): the deals board by stage, the enquiry card edited without
 * overwriting a teammate, and next steps. Enquiries are closed through their stage (Won needs a
 * verified payment or a reference, Lost a reason), never deleted.
 */
final class EnquiryController extends Controller
{
    private const BOARD_LIMIT = 200;

    public function __construct(private readonly TenantContext $context, private readonly EnquiryCards $cards) {}

    /** The board: one column per active stage, with the figures on top. */
    public function board(Request $request): JsonResponse
    {
        $data = $request->validate([
            'owner' => ['nullable', 'string', 'max:40'],                       // a membership id, "me" or "none"
            'filter' => ['nullable', Rule::in(['no_next_step', 'overdue'])],
            'search' => ['nullable', 'string', 'max:80'],
        ]);
        $me = $this->context->membership()?->id;
        $stages = CrmStage::query()->active()->orderBy('position')->orderBy('created_at')->get();
        $since = now()->subDays(30);
        $wonIds = $stages->where('group', CrmStage::WON)->pluck('id');
        $openIds = $stages->where('group', CrmStage::OPEN)->pluck('id');

        // An enquiry with no stage yet (a customer just wrote) sits in the first Open column.
        $first = $openIds->first();
        $query = Enquiry::query()->live()
            ->where(fn ($q) => $q->whereIn('stage_id', $openIds)->when($first !== null, fn ($w) => $w->orWhereNull('stage_id'))
                // Closed enquiries stay on the board for 30 days after closing.
                ->orWhere(fn ($w) => $w->whereIn('stage_id', $stages->pluck('id'))->where('stage_changed_at', '>=', $since)))
            ->when($data['owner'] ?? null, fn ($q, $owner) => match ($owner) {
                'me' => $q->where('owner_membership_id', $me),
                'none' => $q->whereNull('owner_membership_id'),
                default => $q->where('owner_membership_id', $owner),
            })
            ->when(($data['filter'] ?? null) === 'no_next_step', fn ($q) => $q->where(fn ($w) => $w->whereIn('stage_id', $openIds)->orWhereNull('stage_id'))
                ->whereNotExists(fn ($s) => $s->from('next_steps')->whereColumn('next_steps.enquiry_id', 'enquiries.id')->whereNull('done_at')->whereNull('cancelled_at')))
            ->when(($data['filter'] ?? null) === 'overdue', fn ($q) => $q->whereExists(fn ($s) => $s->from('next_steps')->whereColumn('next_steps.enquiry_id', 'enquiries.id')
                ->whereNull('done_at')->whereNull('cancelled_at')->where('due_at', '<', now())))
            ->when($data['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('title', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereIn('contact_id', Contact::query()->where(fn ($c) => $c->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%')->orWhere('profile_name', 'ilike', '%'.addcslashes($term, '%_\\').'%'))->select('id'))))
            ->orderByDesc('last_activity_at')->limit(self::BOARD_LIMIT);
        $rows = collect($this->cards->rows($query->get(), withContact: true))
            ->map(fn (array $r) => $r + ['column' => $r['stage']['id'] ?? $first]);

        $open = $rows->where('open', true);
        $paid30 = (float) DB::table('commerce_orders')->where('paid', true)->where('currency', 'AED')->whereNotNull('enquiry_id')->where('paid_at', '>=', $since)->sum('total');
        $wonMarked = (float) Enquiry::query()->whereIn('stage_id', $wonIds)->where('stage_changed_at', '>=', $since)->sum('value');

        return response()->json(['data' => [
            'stages' => $stages->map(fn (CrmStage $s) => $s->summary() + [
                'enquiries' => $rows->where('column', $s->id)->values()->all(),
                'count' => $rows->where('column', $s->id)->count(),
                'value' => (float) $rows->where('column', $s->id)->sum('value'),
            ])->all(),
            'stats' => [
                'open_value' => (float) $open->sum('value'),
                'open_count' => $open->count(),
                'open_valued' => $open->whereNotNull('value')->count(),
                'won_marked_30d' => $wonMarked,
                'paid_verified_30d' => $paid30,
                'open_without_next_step' => $open->whereNull('next_step')->count(),
            ],
            'truncated' => $rows->count() >= self::BOARD_LIMIT,
        ]]);
    }

    public function show(Enquiry $enquiry): JsonResponse
    {
        return response()->json(['data' => $this->cards->rows(collect([$enquiry]), withContact: true)[0]]);
    }

    /**
     * Edits the card. The screen sends the version it loaded: if a teammate saved in between, nothing
     * is overwritten (409, "This enquiry was updated by …") and the screen reloads their version.
     */
    public function update(Request $request, Enquiry $enquiry, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'],
            'value_period' => ['sometimes', 'nullable', Rule::in(array_keys(Enquiry::PERIODS))],
            'expected_close_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'owner_membership_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        if (array_key_exists('owner_membership_id', $data) && $data['owner_membership_id'] !== null
            && ! TenantMembership::query()->active()->whereKey($data['owner_membership_id'])->exists()) {
            throw ValidationException::withMessages(['owner_membership_id' => 'Choose a teammate in this workspace.']);
        }

        $fresh = DB::transaction(function () use ($enquiry, $data, $audit) {
            /** @var Enquiry $locked */
            $locked = Enquiry::query()->lockForUpdate()->findOrFail($enquiry->id);
            if ($locked->details_version !== (int) $data['version']) {
                $by = $locked->getAttribute('details_updated_by');
                $name = $by !== null ? DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')->where('tenant_memberships.id', $by)->value('users.name') : null;
                throw new StageConflict('This enquiry was updated by '.($name ?? 'someone else').'. Their version is shown now; make your change again.', ['version' => $locked->details_version, 'updated_by' => $name]);
            }
            $changes = array_intersect_key($data, array_flip(['title', 'value', 'value_period', 'expected_close_on', 'owner_membership_id']));
            if (isset($changes['title'])) {
                $changes['title'] = trim((string) $changes['title']) ?: null;
            }
            if (array_key_exists('value', $changes) && $changes['value'] !== null && ($changes['value_period'] ?? $locked->value_period) === null) {
                $changes['value_period'] = 'once';
            }
            $before = array_intersect_key($locked->only(array_keys($changes)), $changes);
            $locked->forceFill($changes + [
                'details_version' => $locked->details_version + 1,
                'details_updated_by' => $this->context->membership()?->id,
                'details_updated_at' => now(),
                'last_activity_at' => now(),
            ])->save();
            $audit->record('enquiry.updated', $locked, before: $before, after: $changes);

            return $locked;
        });

        return response()->json(['data' => $this->cards->rows(collect([$fresh]), withContact: true)[0]]);
    }

    /**
     * "Work on this deal" (3.5): the enquiry the chat's Sales card shows. Viewing another deal never changes
     * it; only this does.
     */
    public function setCurrent(Request $request, Contact $contact, AuditLogger $audit): JsonResponse
    {
        $id = $request->validate(['enquiry_id' => ['required', 'uuid']])['enquiry_id'];
        $enquiry = Enquiry::query()->where('contact_id', $contact->id)->whereKey($id)->firstOrFail();
        DB::table('contacts')->where('id', $contact->id)->update(['current_enquiry_id' => $enquiry->id]);
        $audit->record('enquiry.made_current', $enquiry);

        return response()->json(['data' => $this->cards->rows(collect([$enquiry]), withContact: true)[0]]);
    }

    // ── Next steps ─────────────────────────────────────────────────────────────────────────

    /**
     * Follow-ups across all customers (mobile brief v5): Overdue, Today, Upcoming or Done, with the
     * count of each. People who see every chat choose Mine or Everyone; everyone else sees their own.
     * Each row has the customer and their open chat, for "Open chat".
     */
    public function allSteps(Request $request, NumberAccess $access): JsonResponse
    {
        $data = $request->validate([
            'bucket' => ['nullable', Rule::in(['overdue', 'today', 'upcoming', 'done'])],
            'who' => ['nullable', Rule::in(['mine', 'everyone'])],
        ]);
        $member = $this->context->membership() ?? abort(403);
        $everyone = ($data['who'] ?? 'mine') === 'everyone' && $access->seesAllChats($member->loadMissing('role'));
        $tz = $this->context->tenant()->timezone ?: 'UTC';
        $endOfDay = now($tz)->endOfDay()->utc();
        $base = fn () => NextStep::query()->when(! $everyone, fn ($q) => $q->where('assigned_membership_id', $member->id));
        $bucket = fn (string $b) => match ($b) {
            'overdue' => $base()->open()->where('due_at', '<', now()),
            'today' => $base()->open()->where('due_at', '>=', now())->where('due_at', '<=', $endOfDay),
            'upcoming' => $base()->open()->where('due_at', '>', $endOfDay),
            default => $base()->whereNotNull('done_at')->where('done_at', '>=', now()->subDays(30)),
        };
        $which = $data['bucket'] ?? 'today';
        $rows = $bucket($which)->when($which === 'done', fn ($q) => $q->orderByDesc('done_at'), fn ($q) => $q->orderBy('due_at'))->limit(100)->get();
        $names = $this->names();
        $contacts = Contact::query()->withTrashed()->whereIn('id', $rows->pluck('contact_id')->unique())->get()->keyBy('id');
        $chats = DB::table('conversations')->whereIn('contact_id', $rows->pluck('contact_id')->unique())->where('status', 'open')
            ->orderByDesc('last_message_at')->get(['id', 'contact_id'])->unique('contact_id')->pluck('id', 'contact_id');

        return response()->json(['data' => $rows->map(fn (NextStep $s) => $s->summary($names) + [
            'contact' => ($c = $contacts[$s->contact_id] ?? null) ? ['id' => $c->id, 'name' => $c->displayName(), 'deleted' => $c->trashed()] : null,
            'chat_id' => $chats[$s->contact_id] ?? null,
        ])->values(), 'meta' => [
            'counts' => collect(['overdue', 'today', 'upcoming', 'done'])->mapWithKeys(fn ($b) => [$b => $bucket($b)->count()]),
            'everyone' => $everyone,
            'can_see_everyone' => $access->seesAllChats($member),
        ]]);
    }

    public function steps(Request $request, Contact $contact): JsonResponse
    {
        $all = $request->boolean('all');
        $names = $this->names();
        $steps = NextStep::query()->where('contact_id', $contact->id)->when(! $all, fn ($q) => $q->open())->orderBy('due_at')->limit(100)->get();

        return response()->json(['data' => $steps->map(fn (NextStep $s) => $s->summary($names))->all()]);
    }

    public function addStep(Request $request, Contact $contact, AuditLogger $audit): JsonResponse
    {
        $data = $this->validateStep($request, true);
        if (isset($data['enquiry_id'])) {
            abort_unless(Enquiry::query()->where('contact_id', $contact->id)->whereKey($data['enquiry_id'])->exists(), 422, 'That enquiry belongs to another contact.');
        }
        $step = NextStep::query()->create($data + [
            'contact_id' => $contact->id,
            'created_by_membership_id' => $this->context->membership()?->id,
            'assigned_membership_id' => $data['assigned_membership_id'] ?? $this->context->membership()?->id,
        ]);
        $audit->record('next_step.created', $step, after: ['title' => $step->title, 'due_at' => $step->due_at->toIso8601String()]);
        Timeline::record($contact->id, 'next_step.added', null, 'next_step', $step->id, ['type' => $step->type, 'type_label' => NextStep::TYPES[$step->type] ?? 'Other', 'title' => $step->title, 'due_at' => $step->due_at->toIso8601String()], enquiryId: $step->enquiry_id);

        return response()->json(['data' => $step->summary($this->names())], 201);
    }

    /** Done, reschedule, edit, or cancel (with a reason). */
    public function updateStep(Request $request, NextStep $step, AuditLogger $audit): JsonResponse
    {
        $data = $this->validateStep($request, false) + $request->validate([
            'status' => ['sometimes', Rule::in(['done', 'cancelled', 'open'])],
            'cancel_reason' => ['nullable', 'string', 'max:120'],
        ]);
        $changes = array_intersect_key($data, array_flip(['type', 'title', 'due_at', 'assigned_membership_id']));
        match ($data['status'] ?? null) {
            'done' => $changes += ['done_at' => now(), 'done_by_membership_id' => $this->context->membership()?->id, 'cancelled_at' => null],
            'cancelled' => $changes += ['cancelled_at' => now(), 'cancel_reason' => $data['cancel_reason'] ?? null, 'done_at' => null],
            'open' => $changes += ['cancelled_at' => null, 'done_at' => null, 'cancel_reason' => null],
            default => null,
        };
        $step->forceFill($changes)->save();
        if (in_array($data['status'] ?? null, ['done', 'cancelled'], true)) {
            Timeline::record((string) $step->contact_id, 'next_step.'.$data['status'], null, 'next_step', $step->id, ['type' => $step->type, 'type_label' => NextStep::TYPES[$step->type] ?? 'Other', 'title' => $step->title], enquiryId: $step->enquiry_id);
        }
        $audit->record('next_step.updated', $step, after: array_map(fn ($v) => $v instanceof Carbon ? $v->toIso8601String() : $v, $changes));

        return response()->json(['data' => $step->summary($this->names())]);
    }

    /** @return array<string, mixed> */
    private function validateStep(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'enquiry_id' => ['nullable', 'uuid'],
            'type' => [$req, Rule::in(array_keys(NextStep::TYPES))],
            'title' => [$req, 'string', 'max:160'],
            'due_at' => [$req, 'date'],
            'assigned_membership_id' => ['nullable', 'uuid'],
        ], ['due_at.required' => 'Choose when it is due.', 'title.required' => 'Say what the next step is.']);
        if (isset($data['assigned_membership_id']) && ! TenantMembership::query()->active()->whereKey($data['assigned_membership_id'])->exists()) {
            throw ValidationException::withMessages(['assigned_membership_id' => 'Choose a teammate in this workspace.']);
        }
        if (isset($data['due_at'])) {
            $data['due_at'] = Carbon::parse($data['due_at']);
        }
        if (! $creating) {
            unset($data['enquiry_id']);
        }

        return $data;
    }

    /** @return array<string, string> */
    private function names(): array
    {
        return DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')->pluck('users.name', 'tenant_memberships.id')->all();
    }
}
