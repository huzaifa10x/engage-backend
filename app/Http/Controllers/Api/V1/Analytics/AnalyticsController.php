<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Basic analytics: sent / delivered / read / failed, message volume per day, per number and
 * the top templates — computed from the messages the workspace already stores, limited to the
 * numbers this member may use.
 */
final class AnalyticsController extends Controller
{
    /** Cumulative funnel: a read message was also delivered and sent. */
    private const FUNNEL = "
        count(*) FILTER (WHERE status IN ('accepted','sent','delivered','read')) AS sent,
        count(*) FILTER (WHERE status IN ('delivered','read')) AS delivered,
        count(*) FILTER (WHERE status = 'read') AS read,
        count(*) FILTER (WHERE status = 'failed') AS failed";

    public function overview(Request $request, NumberAccess $access, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'days' => ['nullable', Rule::in([7, 30, 90, '7', '30', '90'])],
            'phone_number_id' => ['nullable', 'uuid'],
        ]);
        $days = (int) ($data['days'] ?? 30);
        $timezone = $context->tenant()->timezone ?? 'UTC';
        $from = now($timezone)->startOfDay()->subDays($days - 1)->utc();

        $membership = $context->membership();
        $granted = $membership !== null ? $access->grantedIds($membership->loadMissing('role')) : null;
        $numbers = PhoneNumber::query()->when($granted !== null, fn (Builder $q) => $q->whereIn('id', $granted ?? []))->get();
        $ids = isset($data['phone_number_id']) ? array_values(array_intersect($numbers->modelKeys(), [$data['phone_number_id']])) : $numbers->modelKeys();

        $messages = fn (): Builder => Message::query()->whereIn('phone_number_id', $ids)->where('created_at', '>=', $from)
            ->whereNotIn('origin', ['history', 'app_echo']);

        $out = $messages()->where('direction', Message::OUTBOUND)->selectRaw('count(*) AS total, '.self::FUNNEL)->toBase()->first();
        $inbound = $messages()->where('direction', Message::INBOUND)->count();

        $day = "to_char(created_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'YYYY-MM-DD')";
        $daily = $messages()->selectRaw("{$day} AS day, count(*) FILTER (WHERE direction = 'inbound') AS inbound, count(*) FILTER (WHERE direction = 'outbound') AS outbound", [$timezone])
            ->groupByRaw('1')->toBase()->get()->keyBy('day');
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->setTimezone($timezone)->addDays($i)->format('Y-m-d');
            $series[] = ['date' => $date, 'inbound' => (int) ($daily[$date]->inbound ?? 0), 'outbound' => (int) ($daily[$date]->outbound ?? 0)];
        }

        $perNumber = $messages()->selectRaw("phone_number_id, count(*) FILTER (WHERE direction = 'inbound') AS inbound, count(*) FILTER (WHERE direction = 'outbound') AS outbound, "
            .str_replace('count(*) FILTER (WHERE', "count(*) FILTER (WHERE direction = 'outbound' AND", self::FUNNEL))
            ->groupBy('phone_number_id')->toBase()->get()->keyBy('phone_number_id');

        $templates = $messages()->where('direction', Message::OUTBOUND)->where('type', 'template')
            ->selectRaw("template->>'name' AS name, count(*) AS total, ".self::FUNNEL)
            ->groupByRaw('1')->orderByDesc('total')->limit(10)->toBase()->get();

        $origins = $messages()->where('direction', Message::OUTBOUND)->selectRaw('origin, count(*) AS total')->groupBy('origin')->toBase()->pluck('total', 'origin');

        return response()->json(['data' => [
            'range' => ['days' => $days, 'from' => $from->toIso8601String(), 'timezone' => $timezone],
            'totals' => [
                'outbound' => (int) ($out->total ?? 0),
                'sent' => (int) ($out->sent ?? 0),
                'delivered' => (int) ($out->delivered ?? 0),
                'read' => (int) ($out->read ?? 0),
                'failed' => (int) ($out->failed ?? 0),
                'inbound' => $inbound,
                'open_conversations' => Conversation::query()->whereIn('phone_number_id', $ids)->where('status', 'open')->count(),
                'contacts' => Contact::query()->count(),
                'new_contacts' => Contact::query()->where('created_at', '>=', $from)->count(),
            ],
            'by_origin' => ['agent' => (int) ($origins['agent'] ?? 0), 'campaign' => (int) ($origins['campaign'] ?? 0), 'api' => (int) ($origins['api'] ?? 0), 'automation' => (int) ($origins['automation'] ?? 0)],
            'daily' => $series,
            'numbers' => $numbers->whereIn('id', $ids)->values()->map(fn (PhoneNumber $n) => [
                'id' => $n->id,
                'display' => $n->verified_name ?? $n->display_phone_number,
                'quality_rating' => $n->quality_rating,
                'inbound' => (int) ($perNumber[$n->id]->inbound ?? 0),
                'outbound' => (int) ($perNumber[$n->id]->outbound ?? 0),
                'sent' => (int) ($perNumber[$n->id]->sent ?? 0),
                'delivered' => (int) ($perNumber[$n->id]->delivered ?? 0),
                'read' => (int) ($perNumber[$n->id]->read ?? 0),
                'failed' => (int) ($perNumber[$n->id]->failed ?? 0),
            ]),
            'top_templates' => $templates->map(fn (object $t) => [
                'name' => (string) ($t->name ?? 'unknown'), 'total' => (int) $t->total, 'sent' => (int) $t->sent,
                'delivered' => (int) $t->delivered, 'read' => (int) $t->read, 'failed' => (int) $t->failed,
            ]),
        ]]);
    }
}
