<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Audit\AuditLogger;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\MissedLeadsReport;
use App\Domain\Messaging\Services\ReplyPromise;
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
 * The reply promise and its pilot kit (3.3, A3): the waiting limit and alert ladder (Settings ›
 * Inbox), each chat's alert log, and the weekly missed-leads report with its week-zero baseline.
 */
final class ReplyPromiseController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => ReplyPromise::settings($this->context->tenant())]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'limit_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'ladder' => ['sometimes', 'array', 'max:5'],
            'ladder.*.minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'ladder.*.to' => ['required', Rule::in(ReplyPromise::ROLES)],
            'owner_whatsapp' => ['sometimes', 'array'],
            'owner_whatsapp.phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ]{8,20}$/'],
            'owner_whatsapp.template' => ['nullable', 'array'],
            'owner_whatsapp.template.name' => ['required_with:owner_whatsapp.template', 'string', 'max:512'],
            'owner_whatsapp.template.language' => ['required_with:owner_whatsapp.template', 'string', 'max:15'],
            'avg_booking_value' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
        ], ['owner_whatsapp.phone.regex' => 'Enter the Owner’s WhatsApp number with its country code.']);

        $minutes = array_column($data['ladder'] ?? [], 'minutes');
        if ($minutes !== [] && count(array_unique($minutes)) !== count($minutes)) {
            throw ValidationException::withMessages(['ladder' => 'Give each alert step a different time.']);
        }

        $tenant = $this->context->tenant();
        $settings = (array) ($tenant->settings ?? []);
        $before = ReplyPromise::settings($tenant);
        $settings['promise'] = array_replace((array) ($settings['promise'] ?? []), $data);
        $tenant->forceFill(['settings' => $settings])->save();
        $after = ReplyPromise::settings($tenant);
        $audit->record('tenant.reply_promise_updated', $tenant, before: array_diff_key($before, ['baseline' => 1]), after: array_diff_key($after, ['baseline' => 1]));
        // Waits already running: the sweep moves their due time and alerts to the new limit.

        return response()->json(['data' => $after]);
    }

    /** Who was alerted while this chat waited (newest first). */
    public function alerts(Conversation $conversation, NumberAccess $access): JsonResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership instanceof TenantMembership, 403);
        $access->ensureCanSeeChat($membership, $conversation);
        $rows = DB::table('wait_alerts')->where('conversation_id', $conversation->id)->orderByDesc('created_at')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn ($r) => [
            'id' => $r->id,
            'level' => (int) $r->level,
            'minutes' => (int) $r->minutes,
            'role' => $r->role,
            'recipients' => json_decode((string) $r->recipients, true),
            'channels' => json_decode((string) $r->channels, true),
            'whatsapp_error' => $r->whatsapp_error,
            'episode' => Carbon::parse($r->episode)->toIso8601String(),
            'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
        ])->all()]);
    }

    public function report(Request $request, MissedLeadsReport $report): JsonResponse
    {
        $weeks = (int) ($request->validate(['weeks_ago' => ['nullable', 'integer', 'min:0', 'max:52']])['weeks_ago'] ?? 0);

        return response()->json(['data' => $report->build($this->context->tenant(), $weeks)]);
    }

    /** The week-zero audit baseline, entered by hand (any field may be left empty). */
    public function baseline(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'enquiries' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'in_time' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'over' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'median_reply_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        if (isset($data['enquiries'], $data['in_time'], $data['over']) && $data['in_time'] + $data['over'] > $data['enquiries']) {
            throw ValidationException::withMessages(['enquiries' => 'Answered in time and waited over can’t add up to more than the enquiries.']);
        }
        $tenant = $this->context->tenant();
        $settings = (array) ($tenant->settings ?? []);
        $empty = array_filter($data, fn ($v) => $v !== null && $v !== '') === [];
        $settings['promise'] = array_replace((array) ($settings['promise'] ?? []), ['baseline' => $empty ? null : $data + ['entered_at' => now()->toIso8601String(), 'entered_by' => $this->context->membership()?->user?->name]]);
        $tenant->forceFill(['settings' => $settings])->save();
        $audit->record('tenant.missed_leads_baseline_set', $tenant, after: $data);

        return response()->json(['data' => ReplyPromise::settings($tenant)['baseline']]);
    }
}
