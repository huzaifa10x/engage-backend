<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Crm;

use App\Domain\Audit\AuditLogger;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contacts & CRM › Fields & sync (3.5). How Engage's fields and stages map to HubSpot and Zoho CRM, kept
 * for when a CRM is connected. Connecting HubSpot or Zoho is not available yet, so every contact shows
 * "Not connected" and nothing leaves Engage.
 */
final class CrmSyncController extends Controller
{
    public const HUBSPOT_STAGES = ['appointmentscheduled', 'qualifiedtobuy', 'presentationscheduled', 'decisionmakerboughtin', 'contractsent', 'closedwon', 'closedlost'];

    public const ZOHO_STAGES = ['Qualification', 'Needs Analysis', 'Value Proposition', 'Identify Decision Makers', 'Proposal/Price Quote', 'Negotiation/Review', 'Closed Won', 'Closed Lost'];

    public function __construct(private readonly TenantContext $context) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings()]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'stage_map' => ['required', 'array', 'max:50'],
            'stage_map.*.hubspot' => ['nullable', Rule::in(self::HUBSPOT_STAGES)],
            'stage_map.*.zoho' => ['nullable', Rule::in(self::ZOHO_STAGES)],
        ]);
        $ids = CrmStage::query()->pluck('id')->flip();
        $map = array_filter($data['stage_map'], fn ($v, $k) => isset($ids[$k]), ARRAY_FILTER_USE_BOTH);
        $tenant = $this->context->tenant();
        $settings = (array) ($tenant->settings ?? []);
        $before = $settings['crm_sync']['stage_map'] ?? [];
        $settings['crm_sync'] = ['provider' => 'none', 'stage_map' => $map];
        $tenant->forceFill(['settings' => $settings])->save();
        $audit->record('crm_sync.stage_map_updated', $tenant, before: ['stage_map' => $before], after: ['stage_map' => $map]);

        return $this->show();
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $saved = (array) (($this->context->tenant()->settings ?? [])['crm_sync']['stage_map'] ?? []);
        $stages = CrmStage::query()->active()->orderBy('position')->get();
        $openIndex = 0;
        $map = $stages->mapWithKeys(function (CrmStage $s) use ($saved, &$openIndex) {
            // Defaults: open stages in order, Won and Lost to the closed stages.
            $default = match ($s->group) {
                CrmStage::WON => ['hubspot' => 'closedwon', 'zoho' => 'Closed Won'],
                CrmStage::LOST => ['hubspot' => 'closedlost', 'zoho' => 'Closed Lost'],
                default => ['hubspot' => self::HUBSPOT_STAGES[min($openIndex, 4)], 'zoho' => self::ZOHO_STAGES[min($openIndex++, 5)]],
            };

            return [$s->id => ['stage' => $s->summary(), 'hubspot' => $saved[$s->id]['hubspot'] ?? $default['hubspot'], 'zoho' => $saved[$s->id]['zoho'] ?? $default['zoho']]];
        });

        return [
            'provider' => 'none',
            'providers_available' => [],      // HubSpot and Zoho CRM come in a later release
            'stage_map' => $map->values()->all(),
            'hubspot_stages' => self::HUBSPOT_STAGES,
            'zoho_stages' => self::ZOHO_STAGES,
        ];
    }
}
