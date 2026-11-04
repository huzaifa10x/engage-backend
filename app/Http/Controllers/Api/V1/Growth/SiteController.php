<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Growth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Growth\GrowthAccess;
use App\Domain\Growth\Models\GrowthSite;
use App\Domain\Growth\Website\Sites;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Growth Hub › Tracking Setup › Websites (Developer Handover §I.4, Technical Specification v1.2 §5,
 * §8): the site cards with health, the 5-step Add website wizard (domain, numbers, install, mode,
 * "Open my site" + live test) and the site's settings. Owner and Admin (growth.manage) change them;
 * growth.view reads them.
 */
final class SiteController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly Sites $sites) {}

    public function index(): JsonResponse
    {
        $this->ensureFull();

        return response()->json(['data' => GrowthSite::query()->orderBy('created_at')->get()->map(fn (GrowthSite $s) => $this->present($s))->values()]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->ensureFull();
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'connected_phone_ids' => ['required', 'array', 'min:1', 'max:20'],
            'connected_phone_ids.*' => ['uuid'],
        ]);
        $site = $this->sites->create($this->context->tenant(), $data['domain'], $data['display_name'] ?? null, $data['connected_phone_ids']);
        $audit->record('growth.site.created', $site, meta: ['domain' => $site->domain]);

        return response()->json(['data' => $this->present($site)], 201);
    }

    public function show(GrowthSite $site): JsonResponse
    {
        $this->ensureFull();

        return response()->json(['data' => $this->present($site)]);
    }

    public function update(Request $request, GrowthSite $site, AuditLogger $audit): JsonResponse
    {
        $this->ensureFull();
        $data = $request->validate([
            'display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'connected_phone_ids' => ['sometimes', 'array', 'min:1', 'max:20'],
            'connected_phone_ids.*' => ['uuid'],
            'consent_mode' => ['sometimes', 'in:click_only,consent'],
            'session_landing_mode' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $site = $this->sites->update($site, $data);
        $audit->record('growth.site.updated', $site, meta: ['changed' => array_keys($data)]);

        return response()->json(['data' => $this->present($site)]);
    }

    public function destroy(GrowthSite $site, AuditLogger $audit): JsonResponse
    {
        $this->ensureFull();
        $audit->record('growth.site.deleted', $site, meta: ['domain' => $site->domain]);
        $site->delete(); // numbers and health go with it; leads keep their evidence

        return response()->json(null, 204);
    }

    public function health(GrowthSite $site): JsonResponse
    {
        $this->ensureFull();

        return response()->json(['data' => $this->sites->health($site)]);
    }

    /** "Open my site": opens the site with a 15-minute challenge the tracker reports back. */
    public function verification(GrowthSite $site): JsonResponse
    {
        $this->ensureFull();

        return response()->json(['data' => $this->sites->startVerification($site)]);
    }

    /** Starts the live test: tracker seen → tap received → message received → reference matched. */
    public function startTest(GrowthSite $site): JsonResponse
    {
        $this->ensureFull();
        $site->forceFill(['test_started_at' => now()])->save();

        return response()->json(['data' => $this->sites->testStatus($site) + $this->sites->startVerification($site)]);
    }

    public function test(GrowthSite $site): JsonResponse
    {
        $this->ensureFull();

        return response()->json(['data' => $this->sites->testStatus($site)]);
    }

    /** @return array<string, mixed> */
    private function present(GrowthSite $site): array
    {
        return [
            'id' => $site->id,
            'domain' => $site->domain,
            'display_name' => $site->display_name,
            'enabled' => $site->enabled,
            'verified_at' => $site->verified_at?->toIso8601String(),
            'consent_mode' => $site->consent_mode,
            'session_landing_mode' => $site->session_landing_mode,
            'site_key' => $site->site_key,
            'snippet' => $this->sites->snippet($site),
            'gtm_snippet' => $this->sites->gtmSnippet($site),
            'privacy_line' => Sites::PRIVACY_LINE,
            'numbers' => $this->sites->numbers($site),
            'health' => $this->sites->health($site),
            'created_at' => $site->created_at?->toIso8601String(),
        ];
    }

    private function ensureFull(): void
    {
        abort_unless(GrowthAccess::full($this->context->tenant()), 404);
    }
}
