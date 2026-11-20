<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Audit\AuditLogger;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationNote;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Messaging\Services\ReplyState;
use App\Domain\Messaging\Services\Resolutions;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inbox › Resolved (3.5): one row per closure, for everyone (agents see the chats they may see).
 * Flag for review and the weekly resolution review are for people who manage the inbox (Owners,
 * Admins, Managers).
 */
final class ResolutionController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly NumberAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent' => ['nullable', 'uuid'],
            'reason' => ['nullable', Rule::in(array_keys(Resolutions::REASONS))],
            'days' => ['nullable', 'integer', 'min:0', 'max:3650'],   // 0 = all time
            'phone_number_id' => ['nullable', 'uuid'],
            'conversation_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $membership = $this->membership();
        $visible = Conversation::query()->select('id');
        $this->access->scopeChats($visible, $membership);
        $visible->when($data['phone_number_id'] ?? null, fn ($q, $id) => $q->where('phone_number_id', $id));
        $days = (int) ($data['days'] ?? 7);

        $rows = DB::table('conversation_resolutions')->whereIn('conversation_id', $visible)
            ->when($data['agent'] ?? null, fn ($q, $id) => $q->where('resolved_by_membership_id', $id))
            ->when($data['reason'] ?? null, fn ($q, $r) => $q->where('reason', $r))
            ->when($data['conversation_id'] ?? null, fn ($q, $id) => $q->where('conversation_id', $id))
            ->when($days > 0 && ! isset($data['conversation_id']), fn ($q) => $q->where('resolved_at', '>=', now()->subDays($days)))
            ->orderByDesc('resolved_at')->limit((int) ($data['per_page'] ?? 100))->get();

        $names = Resolutions::names();
        $contacts = Contact::query()->withTrashed()->whereIn('id', $rows->pluck('contact_id')->filter()->unique())->get()->keyBy('id');
        $later = DB::table('conversation_resolutions')->whereIn('conversation_id', $rows->pluck('conversation_id')->unique())->get(['conversation_id', 'resolved_at'])->groupBy('conversation_id');

        return response()->json(['data' => $rows->map(function ($r) use ($names, $contacts, $later) {
            $c = $contacts[$r->contact_id] ?? null;

            return Resolutions::present($r, $names) + [
                'contact' => $c !== null ? [
                    'id' => $c->id, 'display_name' => $c->displayName(),
                    'last_inbound_at' => $c->last_inbound_at?->toIso8601String(),
                ] : null,
                'later_closures' => $later->get($r->conversation_id, collect())->filter(fn ($x) => Carbon::parse($x->resolved_at)->gt(Carbon::parse($r->resolved_at)))->count(),
            ];
        })->values()->all()]);
    }

    /**
     * A manager flags a closure for review: the chat reopens, goes back to the person who resolved it,
     * and a team-only note carries the manager's reason. The flag stays on that closure.
     */
    public function flag(Request $request, string $resolution, InboxTools $tools, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:300']], ['note.required' => 'Say what should be reviewed.']);
        $row = DB::table('conversation_resolutions')->where('id', $resolution)->first();
        abort_if($row === null, 404);
        $conversation = Conversation::query()->findOrFail($row->conversation_id);
        $this->access->ensureCanSeeChat($this->membership(), $conversation);
        if ($row->flagged_at !== null) {
            throw ValidationException::withMessages(['note' => 'This closure was already flagged.']);
        }

        DB::transaction(function () use ($row, $conversation, $data) {
            DB::table('conversation_resolutions')->where('id', $row->id)->update([
                'flagged_by_membership_id' => $this->membership()->id, 'flag_note' => $data['note'], 'flagged_at' => now(), 'updated_at' => now(),
            ]);
            if ($conversation->status === ConversationStatus::Closed && $row->reopened_at === null) {
                DB::table('conversation_resolutions')->where('id', $row->id)->update(['reopened_at' => now(), 'reopen_how' => 'flag', 'reopened_by_membership_id' => $this->membership()->id]);
                $conversation->forceFill(['status' => ConversationStatus::Open, 'closed_at' => null] + ReplyState::reopened());
            }
            if ($row->resolved_by_membership_id !== null && TenantMembership::query()->active()->whereKey($row->resolved_by_membership_id)->exists()) {
                $conversation->assigned_membership_id = $row->resolved_by_membership_id;
            }
            $conversation->save();
            ConversationNote::query()->create([
                'conversation_id' => $conversation->id, 'membership_id' => $this->membership()->id,
                'body' => 'Flagged for review: '.$data['note'], 'mentions' => array_filter([$row->resolved_by_membership_id]),
            ]);
        });

        $resolver = $row->resolved_by_membership_id !== null ? TenantMembership::query()->active()->find($row->resolved_by_membership_id) : null;
        if ($resolver !== null && $resolver->id !== $this->membership()->id) {
            $name = $this->membership()->user()->value('name') ?? 'A manager';
            $tools->notify($resolver, 'mention', "{$name} flagged a chat you resolved", $data['note'], "/inbox?c={$conversation->id}", email: true);
        }
        $audit->record('conversation.resolution_flagged', $conversation, after: ['resolution_id' => $row->id, 'note' => $data['note']]);

        return response()->json(['data' => Resolutions::present(DB::table('conversation_resolutions')->where('id', $row->id)->first(), Resolutions::names())]);
    }

    /** Resolution review: closures per teammate over the period, with "Not a lead" and flags. */
    public function review(Request $request): JsonResponse
    {
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']])['days'] ?? 7);
        $names = Resolutions::names();
        $rows = DB::table('conversation_resolutions')->whereNotNull('resolved_by_membership_id')->where('resolved_at', '>=', now()->subDays($days))
            ->groupBy('resolved_by_membership_id')
            ->selectRaw("resolved_by_membership_id AS id, count(*) AS total, count(*) FILTER (WHERE reason = 'not_lead') AS not_lead, count(*) FILTER (WHERE reason = 'no_action') AS no_action, count(flagged_at) AS flagged")
            ->get();

        return response()->json(['data' => [
            'days' => $days,
            'agents' => $rows->map(fn ($r) => ['membership_id' => $r->id, 'name' => $names[$r->id] ?? 'Former teammate', 'resolved' => (int) $r->total,
                'not_lead' => (int) $r->not_lead, 'no_action' => (int) $r->no_action, 'flagged' => (int) $r->flagged])->sortByDesc('resolved')->values()->all(),
        ]]);
    }

    private function membership(): TenantMembership
    {
        $m = $this->context->membership();
        abort_unless($m instanceof TenantMembership, 403);

        return $m;
    }
}
