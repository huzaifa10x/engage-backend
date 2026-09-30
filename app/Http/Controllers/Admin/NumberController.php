<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\WhatsApp\ManageChannels;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Exceptions\WhatsappException;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use App\Infrastructure\Meta\MetaApiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Numbers & health: every connected number and its WhatsApp quality signals. */
final class NumberController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'quality' => ['nullable', Rule::in(['GREEN', 'YELLOW', 'RED', 'UNKNOWN'])],
            'status' => ['nullable', Rule::in(['pending', 'connected', 'disconnected'])],
            'type' => ['nullable', Rule::in(['new_number', 'migrated', 'coexistence'])],
            'q' => ['nullable', 'string', 'max:64'],
        ]);

        $numbers = DB::table('phone_numbers as n')
            ->join('tenants as t', 't.id', '=', 'n.tenant_id')
            ->join('waba_accounts as w', 'w.id', '=', 'n.waba_account_id')
            ->when($filters['quality'] ?? null, fn ($q, $v) => $q->where('n.quality_rating', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('n.status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('n.onboarding_type', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('n.display_phone_number', 'ilike', "%{$v}%")->orWhere('n.verified_name', 'ilike', "%{$v}%")->orWhere('t.name', 'ilike', "%{$v}%")))
            ->orderByRaw("CASE n.quality_rating WHEN 'RED' THEN 0 WHEN 'YELLOW' THEN 1 ELSE 2 END")
            ->orderByDesc('n.created_at')
            ->select([
                'n.id', 'n.display_phone_number', 'n.verified_name', 'n.quality_rating', 'n.messaging_limit_tier', 'n.throughput_level',
                'n.status', 'n.onboarding_type', 'n.coexistence_status', 'n.name_status', 'n.last_synced_at',
                't.id as tenant_id', 't.name as tenant', 'w.waba_id', 'w.ban_state', 'w.account_review_status',
            ])
            ->paginate(50)->withQueryString();

        $totals = DB::table('phone_numbers')->selectRaw(
            "count(*) filter (where status = 'connected') as connected, ".
            "count(*) filter (where status = 'pending') as pending, ".
            "count(*) filter (where status = 'disconnected') as disconnected, ".
            "count(*) filter (where status = 'connected' and quality_rating = 'GREEN') as green, ".
            "count(*) filter (where status = 'connected' and quality_rating = 'YELLOW') as yellow, ".
            "count(*) filter (where status = 'connected' and quality_rating = 'RED') as red, ".
            "count(*) filter (where onboarding_type = 'coexistence' and status = 'connected') as coexistence"
        )->first();

        $events = DB::table('quality_events as e')
            ->join('tenants as t', 't.id', '=', 'e.tenant_id')
            ->leftJoin('phone_numbers as n', 'n.id', '=', 'e.phone_number_id')
            ->orderByDesc('e.occurred_at')->limit(20)
            ->get(['e.id', 'e.event_type', 'e.old_value', 'e.new_value', 'e.occurred_at', 't.id as tenant_id', 't.name as tenant', 'n.display_phone_number']);

        return Inertia::render('numbers/Index', [
            'numbers' => $numbers,
            'totals' => array_map('intval', (array) $totals),
            'events' => $events,
            'filters' => (object) $filters,
        ]);
    }

    public function refresh(PhoneNumber $number, TenantContext $context, ManageChannels $channels): RedirectResponse
    {
        $tenant = Tenant::query()->findOrFail($number->tenant_id);

        try {
            $context->run($tenant, fn () => $channels->refreshNumber($number));
        } catch (MetaApiException|WhatsappException $e) {
            return back()->with('error', 'Meta refused the refresh: '.$e->getMessage());
        }

        return back()->with('success', 'Number refreshed from Meta.');
    }
}
