<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\PlatformMetrics;
use Inertia\Inertia;
use Inertia\Response;

final class OverviewController extends Controller
{
    public function __invoke(PlatformMetrics $metrics): Response
    {
        return Inertia::render('Overview', [
            'kpis' => $metrics->kpis(),
            'planMix' => $metrics->planMix(),
            'latestSignups' => $metrics->latestSignups(8),
            'trialsEnding' => $metrics->trialsEnding(7),
            'partnerEligibility' => $metrics->partnerEligibility(),
        ]);
    }
}
