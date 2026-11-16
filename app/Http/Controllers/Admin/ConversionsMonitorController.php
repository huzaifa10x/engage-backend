<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Conversions\Services\ConversionMonitoring;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** Super Admin › Conversions to Meta: success rates, volume, click-ID share, send delay and failures by reason. */
final class ConversionsMonitorController extends Controller
{
    public function __invoke(ConversionMonitoring $monitoring): Response
    {
        return Inertia::render('conversions/Index', ['stats' => $monitoring->stats()]);
    }
}
