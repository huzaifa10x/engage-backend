<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Feedback\FeedbackRequest;
use App\Domain\Feedback\FeedbackSettings;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** CSAT and feedback requests: settings (Follow-ups & Alerts) and results per agent and per week (Analytics). */
final class FeedbackController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): JsonResponse
    {
        $wabas = PhoneNumber::query()->pluck('waba_account_id')->unique()->values();
        $templates = MessageTemplate::query()->whereIn('waba_account_id', $wabas)->where('category', 'UTILITY')->get()
            ->filter(fn (MessageTemplate $t) => $t->isSendable())
            ->map(fn (MessageTemplate $t) => ['id' => $t->id, 'name' => $t->name, 'language' => $t->language, 'body_variables' => count($t->variables()['body']),
                'body' => (string) ($t->component('BODY')['text'] ?? '')])
            ->unique(fn (array $t) => $t['name'].'|'.$t['language'])->values();

        return response()->json(['data' => FeedbackSettings::for($this->context->tenant())->toArray() + ['templates' => $templates]]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'csat_enabled' => ['required', 'boolean'],
            'csat_question' => ['nullable', 'string', 'max:1024'],
            'csat_thanks' => ['nullable', 'string', 'max:1024'],
            'review_enabled' => ['required', 'boolean'],
            'review_on_orders' => ['nullable', 'boolean'],
            'review_on_bookings' => ['nullable', 'boolean'],
            'review_template' => ['nullable', 'array'],
            'review_template.name' => ['required_with:review_template', 'string', 'max:512'],
            'review_template.language' => ['required_with:review_template', 'string', 'max:15'],
            'review_template.body' => ['nullable', 'array', 'max:20'],
            'review_template.body.*' => ['nullable', 'string', 'max:300'],
        ]);
        $template = $data['review_template'] ?? null;
        if ($data['review_enabled'] && $template === null) {
            throw ValidationException::withMessages(['review_template' => 'Choose the approved utility template for feedback requests.']);
        }
        if ($template !== null) {
            /** @var ?MessageTemplate $found */
            $found = MessageTemplate::query()->whereIn('waba_account_id', PhoneNumber::query()->pluck('waba_account_id'))
                ->where('name', $template['name'])->where('language', $template['language'])->get()->first(fn (MessageTemplate $t) => $t->isSendable());
            if ($found === null || strtoupper((string) $found->category) !== 'UTILITY') {
                throw ValidationException::withMessages(['review_template.name' => 'Choose a utility template Meta approved.']);
            }
            $body = array_values(array_map(fn ($v) => trim((string) $v), (array) ($template['body'] ?? [])));
            if (count($body) !== count($found->variables()['body']) || in_array('', $body, true)) {
                throw ValidationException::withMessages(['review_template.body' => 'Fill in every variable of the template, e.g. {{first_name}} or {{reference}} (the order number or booking reference).']);
            }
            $template = ['name' => $found->name, 'language' => $found->language, 'body' => $body];
        }

        $tenant = $this->context->tenant();
        $before = FeedbackSettings::for($tenant)->toArray();
        $settings = (array) ($tenant->settings ?? []);
        $settings['feedback'] = [
            'csat_enabled' => $data['csat_enabled'], 'csat_question' => $data['csat_question'] ?? null, 'csat_thanks' => $data['csat_thanks'] ?? null,
            'review_enabled' => $data['review_enabled'], 'review_on_orders' => $data['review_on_orders'] ?? true, 'review_on_bookings' => $data['review_on_bookings'] ?? true,
            'review_template' => $template,
        ];
        $tenant->forceFill(['settings' => $settings])->save();
        $after = FeedbackSettings::for($tenant)->toArray();
        $audit->record('feedback.settings_updated', $tenant, before: $before, after: $after);

        return $this->show();
    }

    /** Results per agent and per week (workspace time zone, weeks start on Monday). */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $tz = $this->context->tenant()->timezone ?: 'UTC';
        $from = isset($data['from']) ? Carbon::parse($data['from'], $tz)->startOfDay() : now($tz)->subWeeks(8)->startOfWeek();
        $to = isset($data['to']) ? Carbon::parse($data['to'], $tz)->endOfDay() : now($tz)->endOfDay();
        $base = fn () => FeedbackRequest::query()->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()]);
        $counts = "count(*) FILTER (WHERE status IN ('sent','answered')) AS sent,
            count(*) FILTER (WHERE status = 'answered') AS answered,
            count(*) FILTER (WHERE rating = 'good') AS good, count(*) FILTER (WHERE rating = 'ok') AS ok, count(*) FILTER (WHERE rating = 'poor') AS poor,
            count(*) FILTER (WHERE status = 'skipped') AS skipped";
        $row = fn (object $r) => [
            'sent' => (int) $r->sent, 'answered' => (int) $r->answered, 'good' => (int) $r->good, 'ok' => (int) $r->ok, 'poor' => (int) $r->poor, 'skipped' => (int) $r->skipped,
            // CSAT score: the share of answers that were "Good".
            'score' => (int) $r->answered > 0 ? round(100 * (int) $r->good / (int) $r->answered, 1) : null,
            'response_rate' => (int) $r->sent > 0 ? round(100 * (int) $r->answered / (int) $r->sent, 1) : null,
        ];

        $agents = $base()->where('kind', 'csat')->groupBy('agent_membership_id')->selectRaw("agent_membership_id, {$counts}")->toBase()->get();
        $names = TenantMembership::query()->with('user')->whereIn('id', $agents->pluck('agent_membership_id')->filter())->get()->keyBy('id');
        $week = "to_char(date_trunc('week', created_at AT TIME ZONE ".DB::getPdo()->quote($tz)."), 'YYYY-MM-DD')";
        $weeks = $base()->groupByRaw('1')->orderByRaw('1')
            ->selectRaw("{$week} AS week, count(*) FILTER (WHERE kind = 'review' AND status = 'sent') AS reviews, ".str_replace('WHERE ', "WHERE kind = 'csat' AND ", $counts))
            ->toBase()->get();
        $reasons = $base()->where('status', 'skipped')->groupBy('reason')->selectRaw('reason, count(*) AS total')->orderByDesc('total')->toBase()->get();
        $total = $base()->where('kind', 'csat')->selectRaw($counts)->toBase()->first();

        return response()->json(['data' => [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => $tz,
            'totals' => $row($total) + ['reviews_sent' => $base()->where('kind', 'review')->where('status', 'sent')->count()],
            'agents' => $agents->map(fn (object $r) => ['membership_id' => $r->agent_membership_id,
                'name' => $r->agent_membership_id !== null ? ($names[$r->agent_membership_id]->user->name ?? 'Former teammate') : 'No agent'] + $row($r))
                ->sortByDesc('sent')->values(),
            // CSAT per week; reviews = feedback / review requests sent that week.
            'weeks' => $weeks->map(fn (object $r) => ['week' => $r->week, 'reviews' => (int) $r->reviews] + $row($r))->values(),
            'skipped' => $reasons->map(fn (object $r) => ['reason' => (string) $r->reason, 'count' => (int) $r->total])->values(),
        ]]);
    }
}
