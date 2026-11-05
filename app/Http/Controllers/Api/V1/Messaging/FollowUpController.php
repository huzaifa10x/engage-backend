<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Domain\Audit\AuditLogger;
use App\Domain\Automation\FollowUps;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Settings › Follow-up rules (Engagement Plan §IV): the rules, what each did in the last 30 days
 * (fired, customer replied afterwards), and — before a customer nudge is turned on — what it would
 * cost: how many nudges a month, and how they sit against Meta's free service messages per number.
 */
final class FollowUpController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): JsonResponse
    {
        $tenant = $this->context->tenant();

        return response()->json(['data' => [
            'settings' => FollowUps::settings($tenant),
            'allowed' => FollowUps::allowed($tenant),
            'stats' => $this->stats(),
            'estimate' => $this->estimate(),
        ]]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'reply_alert' => ['sometimes', 'array'],
            'reply_alert.enabled' => ['boolean'],
            'reply_alert.minutes' => ['integer', 'min:1', 'max:1440'],
            'escalation' => ['sometimes', 'array'],
            'escalation.enabled' => ['boolean'],
            'escalation.minutes' => ['integer', 'min:1', 'max:1440'],
            'escalation.action' => ['in:notify,reassign'],
            'escalation.notify_ids' => ['array', 'max:20'],
            'escalation.notify_ids.*' => ['uuid'],
            'window_alert' => ['sometimes', 'array'],
            'window_alert.enabled' => ['boolean'],
            'window_alert.minutes_before' => ['integer', 'min:15', 'max:720'],
            'unassigned' => ['sometimes', 'array'],
            'unassigned.enabled' => ['boolean'],
            'unassigned.minutes' => ['integer', 'min:1', 'max:1440'],
            'nudge' => ['sometimes', 'array'],
            'nudge.enabled' => ['boolean'],
            'nudge.hours' => ['integer', 'min:1', 'max:23'],
            'nudge.message' => ['nullable', 'string', 'max:1000'],
            'second_nudge' => ['sometimes', 'array'],
            'second_nudge.enabled' => ['boolean'],
            'second_nudge.hours' => ['integer', 'min:1', 'max:168'],
            'second_nudge.template' => ['nullable', 'array'],
            'second_nudge.template.name' => ['required_with:second_nudge.template', 'string', 'max:512'],
            'second_nudge.template.language' => ['required_with:second_nudge.template', 'string', 'max:15'],
            'second_nudge.template.body' => ['array', 'max:10'],
            'second_nudge.template.body.*' => ['string', 'max:200'],
            'weekly_cap' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'free_service_messages' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);
        $tenant = $this->context->tenant();
        $allowed = FollowUps::allowed($tenant);
        foreach (['escalation' => 'escalation', 'nudge' => 'nudges', 'second_nudge' => 'nudges', 'unassigned' => 'round_robin', 'reply_alert' => 'team', 'window_alert' => 'team'] as $rule => $feature) {
            if (($data[$rule]['enabled'] ?? false) && ! $allowed[$feature]) {
                throw ValidationException::withMessages([$rule => 'This follow-up rule is not included in your plan.']);
            }
        }
        if (($data['nudge']['enabled'] ?? false) && trim((string) ($data['nudge']['message'] ?? FollowUps::settings($tenant)['nudge']['message'])) === '') {
            throw ValidationException::withMessages(['nudge.message' => 'Write the nudge message.']);
        }
        if (($data['second_nudge']['enabled'] ?? false) && empty($data['second_nudge']['template'] ?? FollowUps::settings($tenant)['second_nudge']['template'])) {
            throw ValidationException::withMessages(['second_nudge.template' => 'Choose an approved template for the second nudge.']);
        }
        foreach ($data['escalation']['notify_ids'] ?? [] as $id) {
            if (! TenantMembership::query()->whereKey($id)->exists()) {
                throw ValidationException::withMessages(['escalation.notify_ids' => 'Choose teammates of this workspace.']);
            }
        }

        $settings = $tenant->settings ?? [];
        $before = (array) ($settings['followups'] ?? []);
        $next = $before;
        foreach ($data as $key => $value) {
            $next[$key] = is_array($value) ? array_merge((array) ($before[$key] ?? []), $value) : $value;
        }
        $settings['followups'] = $next;
        $tenant->forceFill(['settings' => $settings])->save();
        $audit->record('tenant.followups_updated', $tenant, before: $before, after: $next);

        return $this->show();
    }

    /**
     * Last 30 days per rule: how often it fired and how many customers replied afterwards (within
     * 24 h). "Orders after follow-up" arrives with the WooCommerce step.
     *
     * @return array<string, array{fired: int, replied: int, skipped: int}>
     */
    private function stats(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT a.kind,
                   count(*) FILTER (WHERE a.status = 'done') AS fired,
                   count(*) FILTER (WHERE a.status = 'skipped') AS skipped,
                   count(*) FILTER (WHERE a.status = 'done' AND EXISTS (
                       SELECT 1 FROM messages m WHERE m.conversation_id = a.conversation_id AND m.direction = 'inbound'
                         AND m.created_at > a.decided_at AND m.created_at <= a.decided_at + interval '24 hours')) AS replied
            FROM scheduled_actions a
            WHERE a.tenant_id = ? AND a.decided_at >= now() - interval '30 days'
            GROUP BY a.kind
        SQL, [$this->context->tenant()->id]);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->kind] = ['fired' => (int) $r->fired, 'replied' => (int) $r->replied, 'skipped' => (int) $r->skipped];
        }

        return $out;
    }

    /**
     * What a customer nudge would cost, from the last 30 days: replies by a person after which the
     * customer stayed silent for the nudge delay while the window was still open (each would have
     * got a nudge), and the service messages each number already sent this month (non-template,
     * outside Meta's free ad-lead window), against the free monthly allowance.
     *
     * @return array<string, mixed>
     */
    private function estimate(): array
    {
        $tenant = $this->context->tenant();
        $s = FollowUps::settings($tenant);
        $hours = $s['nudge']['hours'];
        $nudges = (int) (DB::selectOne(<<<'SQL'
            SELECT count(*) AS n FROM messages m
            JOIN conversations c ON c.id = m.conversation_id
            WHERE m.tenant_id = ? AND m.direction = 'outbound' AND m.origin IN ('agent', 'app_echo')
              AND m.created_at >= now() - interval '30 days' AND m.created_at <= now() - make_interval(hours => ?)
              AND NOT EXISTS (SELECT 1 FROM messages x WHERE x.conversation_id = m.conversation_id AND x.created_at > m.created_at
                              AND x.created_at <= m.created_at + make_interval(hours => ?)
                              AND (x.direction = 'inbound' OR x.origin IN ('agent', 'app_echo')))
              AND EXISTS (SELECT 1 FROM messages i WHERE i.conversation_id = m.conversation_id AND i.direction = 'inbound'
                              AND i.created_at <= m.created_at AND i.created_at > m.created_at + make_interval(hours => ?) - interval '24 hours')
        SQL, [$tenant->id, $hours, $hours, $hours])->n ?? 0);

        $service = DB::table('messages')->where('tenant_id', $tenant->id)->where('direction', 'outbound')->where('type', '!=', 'template')
            ->whereNotIn('origin', ['history', 'app_echo'])->where('created_at', '>=', now()->startOfMonth())
            ->whereRaw("coalesce(pricing->>'type', '') <> 'free_entry_point'")
            ->selectRaw('phone_number_id, count(*) AS n')->groupBy('phone_number_id')->pluck('n', 'phone_number_id');
        $free = $s['free_service_messages'];
        $numbers = PhoneNumber::query()->get()->map(fn (PhoneNumber $n) => [
            'id' => $n->id, 'display' => $n->verified_name ?? $n->display_phone_number,
            'service_messages_this_month' => (int) ($service[$n->id] ?? 0),
            'free_left' => max(0, $free - (int) ($service[$n->id] ?? 0)),
        ])->values();
        $freeLeft = (int) $numbers->sum('free_left');

        return [
            'nudges_per_month' => $nudges,
            'nudge_hours' => $hours,
            'free_service_messages' => $free,
            'within_free' => min($nudges, $freeLeft),
            'beyond_free' => max(0, $nudges - $freeLeft),
            'numbers' => $numbers,
        ];
    }
}
