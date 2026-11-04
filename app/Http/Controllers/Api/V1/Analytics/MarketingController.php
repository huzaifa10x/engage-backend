<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Domain\Audit\AuditLogger;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Growth\GrowthAccess;
use App\Domain\Growth\Marketing\MarketingCohort;
use App\Domain\Growth\Marketing\MarketingReport;
use App\Domain\Growth\Marketing\MarketingRollup;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analytics → Marketing (Marketing Analytics Specification §7): overview, sources, campaigns, funnel,
 * trend, stage mapping and export. Owner and Admin (growth.view); the mapping is edited with
 * growth.manage. Growth, Pro and Enterprise only (CN-01); Starter gets 402 with an upgrade prompt.
 */
final class MarketingController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly MarketingRollup $rollup) {}

    public function overview(Request $request): JsonResponse
    {
        $report = $this->report($request);

        return response()->json(['data' => $report->overview($request->input('compare', 'previous') !== 'none')]);
    }

    public function sources(Request $request): JsonResponse
    {
        return response()->json(['data' => ['rows' => $this->report($request)->sources()]]);
    }

    public function campaigns(Request $request): JsonResponse
    {
        return response()->json(['data' => ['rows' => $this->report($request)->campaigns(min(100, max(1, (int) $request->input('limit', 50))))]]);
    }

    public function funnel(Request $request): JsonResponse
    {
        return response()->json(['data' => ['steps' => $this->report($request)->funnel()]]);
    }

    public function trend(Request $request): JsonResponse
    {
        return response()->json(['data' => ['points' => $this->report($request)->trend($request->input('compare', 'previous') !== 'none')]]);
    }

    public function mapping(): JsonResponse
    {
        $tenant = $this->tenant();
        $mapping = MarketingCohort::mapping($tenant);

        return response()->json(['data' => $mapping + ['ready' => $mapping['qualified'] !== []]]);
    }

    /** PUT stage-mapping {contacted: [stage ids], qualified: [stage ids]}: recalculates the whole history. */
    public function updateMapping(Request $request, AuditLogger $audit): JsonResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'contacted' => ['present', 'array', 'max:20'], 'contacted.*' => ['uuid', 'distinct'],
            'qualified' => ['present', 'array', 'max:20'], 'qualified.*' => ['uuid', 'distinct'],
        ]);
        $ids = array_values(array_unique([...$data['contacted'], ...$data['qualified']]));
        if (CrmStage::query()->whereIn('id', $ids)->count() !== count($ids)) { // tenant-scoped
            throw ValidationException::withMessages(['qualified' => 'Choose stages from this workspace.']);
        }
        if (array_intersect($data['contacted'], $data['qualified']) !== []) {
            throw ValidationException::withMessages(['qualified' => 'A stage can be Contacted or Qualified, not both.']);
        }

        DB::transaction(function () use ($tenant, $data) {
            DB::table('growth_stage_mapping')->where('tenant_id', $tenant->id)->delete();
            $by = $this->context->membership()?->id;
            foreach (['contacted', 'qualified'] as $step) {
                foreach ($data[$step] as $id) {
                    DB::table('growth_stage_mapping')->insert(['tenant_id' => $tenant->id, 'reporting_step' => $step, 'crm_stage_id' => $id, 'updated_by' => $by, 'updated_at' => now()]);
                }
            }
        });
        $audit->record('growth.stage_mapping_updated', null, after: $data);

        $this->rollup->rebuild($tenant); // "Changing the mapping recalculates history."

        return $this->mapping();
    }

    /** CSV: the sources and campaigns of the period. Counts only; no codes, click IDs or costs. */
    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        $report = $this->report($request);
        $audit->record('growth.marketing_exported', null, meta: ['from' => $report->from(), 'to' => $report->to()]);
        $sources = $report->sources();
        $campaigns = $report->campaigns(100);

        return response()->streamDownload(function () use ($sources, $campaigns) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            $safe = fn (?string $v) => $v !== null && preg_match('/^[=+\-@]/', $v) === 1 ? "'".$v : (string) $v;
            fputcsv($out, ['Type', 'Name', 'Source', 'New leads', 'Qualified', 'Qualification rate %', 'Previous new leads'], escape: '');
            foreach ($sources as $r) {
                fputcsv($out, ['Source', $r['label'], $r['label'], $r['new_leads'], $r['qualified'] ?? '', $r['rate'] ?? '', $r['previous']['new_leads']], escape: '');
            }
            foreach ($campaigns as $r) {
                fputcsv($out, ['Campaign', $safe($r['label']), $r['source'], $r['new_leads'], $r['qualified'] ?? '', $r['rate'] ?? '', $r['previous_new_leads']], escape: '');
            }
            fclose($out);
        }, 'marketing-'.$report->from().'-to-'.$report->to().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function tenant(): Tenant
    {
        $tenant = $this->context->tenant();
        if (GrowthAccess::tier($tenant) === GrowthAccess::OFF) {
            abort(404);
        }
        // Starter (chip tier): the menu item shows an upgrade prompt; no numbers.
        abort_unless(GrowthAccess::full($tenant), 402, 'Analytics → Marketing is included in the Growth, Pro and Enterprise plans.');

        return $tenant;
    }

    private function report(Request $request): MarketingReport
    {
        $tenant = $this->tenant();
        $f = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'compare' => ['nullable', 'in:previous,none'],
            'number_id' => ['nullable', 'uuid'],
            'source' => ['nullable', 'in:meta,'.implode(',', array_keys(MarketingCohort::BUCKETS))],
            'campaign' => ['nullable', 'string', 'max:120'],
        ]);
        if (Carbon::parse($f['from'])->diffInDays(Carbon::parse($f['to'])) > 366) {
            throw ValidationException::withMessages(['to' => 'Choose a period of one year or less.']);
        }
        // First view: build the history once, then the scheduled refresh keeps it current.
        if (! $this->rollup->hasRun($tenant)) {
            $this->rollup->rebuild($tenant);
        }

        return new MarketingReport($tenant, $f);
    }
}
