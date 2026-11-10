<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Growth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Commerce\CommerceAccess;
use App\Domain\Commerce\Models\CommerceOrder;
use App\Domain\Commerce\Services\Orders;
use App\Domain\Commerce\Services\SalesPresenter;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Growth Hub › Sales (Engagement Plan v4 §V.3): the store orders and the evidence for each match,
 * the way Leads works. Plus the conversation sidebar's "Orders" (every agent who sees the chat:
 * the contact's orders, their status and total spend, never ad cost).
 */
final class SalesController extends Controller
{
    private const RULES = [
        'match' => ['nullable', 'in:all,link,phone,review,unavailable,none'],
        'status' => ['nullable', 'string', 'max:24'],
        'q' => ['nullable', 'string', 'max:100'],
        'days' => ['nullable', 'integer', 'in:7,30,90,365'],
        'store' => ['nullable', 'uuid'],
        // The number chosen in the top bar (CN-02): orders matched to chats on that number.
        'phone_number_id' => ['nullable', 'uuid'],
        'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        'cursor' => ['nullable', 'string'],
    ];

    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureAllowed();
        $f = $request->validate(self::RULES);

        $page = $this->query($f, withMatch: true)->orderByDesc('placed_at')->orderByDesc('id')->cursorPaginate((int) ($f['per_page'] ?? 50));
        $contacts = Contact::query()->whereIn('id', collect($page->items())->pluck('contact_id')->filter()->unique()->all())->get()->keyBy('id');

        $counts = $this->query($f, withMatch: false)->select('match', DB::raw('count(*) as n'))->groupBy('match')->pluck('n', 'match')->map(fn ($n) => (int) $n)->all();
        $counts['all'] = array_sum($counts);

        return response()->json([
            'data' => collect($page->items())->map(fn (CommerceOrder $o) => SalesPresenter::row($o, $o->contact_id !== null ? $contacts[$o->contact_id] ?? null : null))->values(),
            'meta' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'counts' => $counts + ['link' => 0, 'phone' => 0, 'review' => 0, 'unavailable' => 0, 'none' => 0],
                'stores' => DB::table('integrations')->where('tenant_id', $this->context->id())->where('provider', 'woocommerce')->where('external_id', 'not like', 'pending:%')
                    ->orderBy('name')->get(['id', 'name'])->map(fn (object $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            ],
        ]);
    }

    public function show(CommerceOrder $order): JsonResponse
    {
        $this->ensureAllowed();

        return response()->json(['data' => SalesPresenter::details($order, $order->contact_id !== null ? Contact::query()->find($order->contact_id) : null)]);
    }

    /** "Phone differs" orders: attach to the chat (counts as a sale from the chat), or keep apart. */
    public function review(Request $request, CommerceOrder $order, Orders $orders): JsonResponse
    {
        $this->ensureAllowed();
        $data = $request->validate(['action' => ['required', 'in:attach,keep_apart']]);
        abort_unless($order->match === CommerceOrder::REVIEW, 422, 'This order does not need a review.');
        $order = $orders->review($order, $data['action'] === 'attach');
        $this->audit->record('commerce.order_reviewed', null, after: ['match' => $order->match], meta: ['order_id' => $order->id, 'number' => $order->number]);

        return response()->json(['data' => SalesPresenter::details($order, $order->contact_id !== null ? Contact::query()->find($order->contact_id) : null)]);
    }

    /** The conversation sidebar: this contact's orders and total spend (net of refunds). */
    public function forContact(Contact $contact): JsonResponse
    {
        if (! CommerceAccess::allows($this->context->tenant())) {
            return response()->json(['data' => ['orders' => [], 'total_spend' => [], 'count' => 0]]);
        }
        $orders = CommerceOrder::query()->where('contact_id', $contact->id)->whereNot('match', CommerceOrder::REVIEW)->orderByDesc('placed_at')->limit(20)->get();
        $spend = DB::table('commerce_orders')->where('tenant_id', $contact->tenant_id)->where('contact_id', $contact->id)->where('match', '!=', CommerceOrder::REVIEW)
            ->select('currency', DB::raw('sum(net) as net'), DB::raw('count(*) as n'))->groupBy('currency')->get();

        return response()->json(['data' => [
            'orders' => $orders->map(fn (CommerceOrder $o) => [
                'id' => $o->id, 'number' => $o->number, 'store' => $o->store_name, 'status' => $o->status, 'currency' => $o->currency,
                'total' => SalesPresenter::money($o->total), 'net' => SalesPresenter::money($o->net), 'match' => $o->match,
                'match_label' => CommerceOrder::LABELS[$o->match] ?? $o->match, 'placed_at' => $o->placed_at->toIso8601String(), 'items_count' => $o->items_count,
            ])->values(),
            'total_spend' => $spend->map(fn (object $s) => ['currency' => $s->currency, 'net' => SalesPresenter::money($s->net)])->values(),
            'count' => (int) $spend->sum('n'),
        ]]);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return Builder<CommerceOrder>
     */
    private function query(array $f, bool $withMatch): Builder
    {
        $q = CommerceOrder::query()->where('placed_at', '>=', now()->subDays((int) ($f['days'] ?? 30)));
        if ($withMatch && ($f['match'] ?? 'all') !== 'all') {
            $q->where('match', $f['match']);
        }
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['store'])) {
            $q->where('integration_id', $f['store']);
        }
        if (! empty($f['phone_number_id'])) {
            $q->whereIn('conversation_id', DB::table('conversations')->where('phone_number_id', $f['phone_number_id'])->select('id'));
        }
        if (! empty($f['q'])) {
            $term = trim((string) $f['q']);
            $digits = preg_replace('/\D+/', '', $term) ?? '';
            $q->where(function (Builder $w) use ($term, $digits) {
                $w->where('number', 'ilike', '%'.addcslashes(ltrim($term, '#'), '%_\\').'%')
                    ->orWhere('billing_name', 'ilike', '%'.addcslashes($term, '%_\\').'%');
                if (strlen($digits) >= 4) {
                    $w->orWhere('billing_phone', 'like', '%'.$digits.'%');
                }
            });
        }

        return $q;
    }

    private function ensureAllowed(): void
    {
        CommerceAccess::ensure($this->context->tenant());
    }
}
