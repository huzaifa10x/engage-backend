<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Growth;

use App\Domain\Growth\GrowthAccess;
use App\Domain\Growth\Services\MetaAds;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Api\V1\Growth\LeadEvidenceController as Evidence;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tracking Setup › Meta ads: the Click-to-WhatsApp ads leads came from, with how many leads each
 * brought, and the "runs on one platform only" tag that proves Instagram or Facebook.
 */
final class MetaAdController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): JsonResponse
    {
        abort_unless(GrowthAccess::full($this->context->tenant()), 404);

        $tenant = $this->context->tenant()->id;
        $leads = DB::table('contacts')->where('tenant_id', $tenant)->whereNull('deleted_at')->whereNotNull('acquisition_original')
            ->selectRaw("acquisition_original->>'ad_id' AS ad, count(*) AS n")
            ->groupByRaw("acquisition_original->>'ad_id'")->pluck('n', 'ad');
        $touches = DB::table('growth_evidence')->where('tenant_id', $tenant)->where('evidence_type', 'meta_ctwa')
            ->selectRaw("provider_fields->'referral'->>'source_id' AS ad, count(*) AS n")
            ->groupByRaw("provider_fields->'referral'->>'source_id'")->pluck('n', 'ad');

        $ads = DB::table('growth_meta_ads')->where('tenant_id', $tenant)->orderByDesc('last_seen_at')->limit(500)->get();

        return response()->json(['data' => $ads->map(fn (object $a) => [
            'id' => $a->id,
            'ad_id' => Evidence::mask((string) $a->source_id),
            'source_type' => $a->source_type,
            'headline' => $a->headline,
            'source_url' => $a->source_url,
            'platform' => $a->platform,
            'leads' => (int) ($leads[$a->source_id] ?? 0),       // new leads whose original source is this ad
            'messages' => (int) ($touches[$a->source_id] ?? 0),  // every chat start from this ad, returning customers included
            'first_seen_at' => Carbon::parse((string) $a->first_seen_at)->toIso8601String(),
            'last_seen_at' => Carbon::parse((string) $a->last_seen_at)->toIso8601String(),
        ])->values()]);
    }

    public function update(Request $request, string $ad, MetaAds $ads): JsonResponse
    {
        abort_unless(GrowthAccess::full($this->context->tenant()), 404);
        $data = $request->validate(['platform' => ['present', 'nullable', 'in:instagram,facebook']]);

        $changed = $ads->tag($this->context->tenant(), $ad, $data['platform'], $this->context->membership()?->id);

        return response()->json(['data' => ['platform' => $data['platform'], 'leads_updated' => $changed]]);
    }
}
