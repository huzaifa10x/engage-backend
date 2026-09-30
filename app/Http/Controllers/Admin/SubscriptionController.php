<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\PlatformMetrics;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class SubscriptionController extends Controller
{
    public function index(PlatformMetrics $metrics): Response
    {
        $events = DB::table('subscription_events as e')
            ->join('tenants as t', 't.id', '=', 'e.tenant_id')
            ->orderByDesc('e.occurred_at')->limit(25)
            ->get(['e.id', 'e.type', 'e.from_status', 'e.to_status', 'e.occurred_at', 't.id as tenant_id', 't.name as tenant'])
            ->map(fn ($r) => (array) $r);

        return Inertia::render('subscriptions/Index', [
            'kpis' => $metrics->kpis(),
            'planMix' => $metrics->planMix(),
            'trialsEnding' => $metrics->trialsEnding(14),
            'events' => $events,
        ]);
    }
}
