<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Domain\Commerce\CommerceAccess;
use App\Domain\Commerce\Models\CommerceOrder;
use App\Domain\Commerce\Services\SalesPresenter;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Analytics › Sales & Revenue (Engagement Plan v4 §V.3), next to Marketing.
 *
 *  - "Revenue from tracked chat links": only orders whose chat-link token came back (match = link).
 *  - Orders from WhatsApp contacts (phone match) in their own column, never added to chat revenue.
 *  - Net revenue after refunds and cancellations, average order value, sales by the contact's
 *    original source and by the source of the chat the link came from, products sold by source,
 *    and the time from first chat to order.
 *
 * Revenue is in the store's currency (AED); orders in other currencies are reported apart. Orders
 * placed in the period, in their latest state.
 */
final class SalesAnalyticsController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $this->context->tenant();
        if (! CommerceAccess::allows($tenant)) {
            return response()->json(['data' => ['locked' => true]]);
        }
        $f = $request->validate(['days' => ['nullable', 'integer', 'in:7,30,90,365'], 'phone_number_id' => ['nullable', 'uuid']]);
        $days = (int) ($f['days'] ?? 30);
        // The number chosen in the top bar (CN-02): only orders matched to chats on that number count.
        $number = $f['phone_number_id'] ?? null;
        $since = now()->subDays($days);
        $tz = $tenant->timezone ?: 'Asia/Dubai';

        $base = fn () => DB::table('commerce_orders')->where('tenant_id', $tenant->id)->where('placed_at', '>=', $since)
            ->when($number, fn ($q, string $id) => $q->whereIn('conversation_id', DB::table('conversations')->where('phone_number_id', $id)->select('id')));
        $currency = (string) ($base()->select('currency', DB::raw('count(*) n'))->groupBy('currency')->orderByDesc('n')->value('currency') ?? 'AED');
        $inCurrency = fn () => $base()->where('currency', $currency);

        $byMatch = $inCurrency()->select('match', DB::raw('count(*) as orders'), DB::raw('count(*) filter (where paid) as paid_orders'), DB::raw('coalesce(sum(net), 0) as net'), DB::raw('coalesce(sum(refunded) filter (where paid or status = \'refunded\'), 0) as refunded'))
            ->groupBy('match')->get()->keyBy('match');
        $col = function (string $match) use ($byMatch): array {
            $r = $byMatch[$match] ?? null;
            $paid = (int) ($r->paid_orders ?? 0);
            $net = (float) ($r->net ?? 0);

            return ['orders' => (int) ($r->orders ?? 0), 'paid_orders' => $paid, 'revenue' => SalesPresenter::money($net), 'aov' => SalesPresenter::money($paid > 0 ? $net / $paid : 0), 'refunded' => SalesPresenter::money($r->refunded ?? 0)];
        };
        $all = $inCurrency()->selectRaw('count(*) as orders, count(*) filter (where paid) as paid_orders, coalesce(sum(net), 0) as net, coalesce(sum(refunded), 0) as refunded')->first();

        $bySource = function (string $column) use ($inCurrency): array {
            return $inCurrency()->where('match', CommerceOrder::LINK)
                ->selectRaw("coalesce({$column}->>'label', 'No tracked source') as label, coalesce({$column}->>'source', 'untracked') as source, count(*) as orders, coalesce(sum(net), 0) as net")
                ->groupByRaw('1, 2')->orderByDesc('net')->limit(20)->get()
                ->map(fn (object $r) => ['label' => $r->label, 'source' => $r->source, 'orders' => (int) $r->orders, 'revenue' => SalesPresenter::money($r->net)])->values()->all();
        };

        $products = DB::table('commerce_order_items as i')->join('commerce_orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.tenant_id', $tenant->id)->where('o.placed_at', '>=', $since)->where('o.currency', $currency)->where('o.paid', true)
            ->whereIn('o.match', [CommerceOrder::LINK, CommerceOrder::PHONE])
            ->when($number, fn ($q, string $id) => $q->whereIn('o.conversation_id', DB::table('conversations')->where('phone_number_id', $id)->select('id')))
            ->selectRaw("i.name, coalesce(o.conversation_source->>'label', o.original_source->>'label', 'No tracked source') as source, o.match, sum(i.quantity) as quantity, sum(i.total) as revenue")
            ->groupByRaw('1, 2, 3')->orderByDesc('revenue')->limit(25)->get()
            ->map(fn (object $r) => ['name' => $r->name, 'source' => $r->source, 'match' => $r->match, 'quantity' => (int) $r->quantity, 'revenue' => SalesPresenter::money($r->revenue)])->values();

        $toOrder = $inCurrency()->where('match', CommerceOrder::LINK)->whereNotNull('first_chat_at')
            ->selectRaw('percentile_cont(0.5) within group (order by extract(epoch from (placed_at - first_chat_at))) as median, avg(extract(epoch from (placed_at - first_chat_at))) as average')->first();

        $daily = $inCurrency()->selectRaw("to_char((placed_at at time zone ?)::date, 'YYYY-MM-DD') as day, coalesce(sum(net) filter (where match = 'link'), 0) as link, coalesce(sum(net) filter (where match = 'phone'), 0) as phone, coalesce(sum(net) filter (where match not in ('link', 'phone')), 0) as other", [$tz])
            ->groupByRaw('1')->orderByRaw('1')->get()
            ->map(fn (object $r) => ['day' => $r->day, 'link' => SalesPresenter::money($r->link), 'phone' => SalesPresenter::money($r->phone), 'other' => SalesPresenter::money($r->other)])->values();

        $stores = DB::table('integrations')->where('tenant_id', $tenant->id)->where('provider', 'woocommerce')->where('external_id', 'not like', 'pending:%')->count();

        return response()->json(['data' => [
            'locked' => false,
            'stores' => $stores,
            // Filtered by a number: only orders matched to chats on it (store-wide columns are then empty).
            'number_filtered' => $number !== null,
            'range' => ['days' => $days, 'from' => $since->toIso8601String(), 'timezone' => $tz],
            'currency' => $currency,
            'other_currencies' => $base()->where('currency', '!=', $currency)->distinct()->pluck('currency')->values(),
            'chat_link' => $col(CommerceOrder::LINK),
            'phone' => $col(CommerceOrder::PHONE),
            'review' => $col(CommerceOrder::REVIEW),
            'unavailable' => $col(CommerceOrder::UNAVAILABLE),
            'website' => $col(CommerceOrder::NONE),
            'total' => [
                'orders' => (int) ($all->orders ?? 0), 'paid_orders' => (int) ($all->paid_orders ?? 0),
                'net' => SalesPresenter::money($all->net ?? 0), 'refunded' => SalesPresenter::money($all->refunded ?? 0),
                'aov' => SalesPresenter::money(((int) ($all->paid_orders ?? 0)) > 0 ? (float) $all->net / (int) $all->paid_orders : 0),
            ],
            'by_original_source' => $bySource('original_source'),
            'by_conversation_source' => $bySource('conversation_source'),
            'products' => $products,
            'time_to_order' => ['median_hours' => $toOrder?->median !== null ? round((float) $toOrder->median / 3600, 1) : null, 'average_hours' => $toOrder?->average !== null ? round((float) $toOrder->average / 3600, 1) : null],
            'daily' => $daily,
        ]]);
    }
}
