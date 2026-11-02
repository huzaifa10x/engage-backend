<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Growth;

use App\Domain\Growth\GrowthSettings;
use App\Domain\Growth\Models\GrowthEvidence;
use App\Domain\Growth\Services\MetaReferral;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET growth/leads/{contact}/evidence — the proof behind a contact's source (growth.view only).
 * IDs Meta or Google give (ad ID, ctwa_clid) are masked; agents never reach this endpoint.
 */
final class LeadEvidenceController extends Controller
{
    public function __invoke(Contact $contact, TenantContext $context): JsonResponse
    {
        abort_unless(GrowthSettings::for($context->tenant())->enabled, 404);

        $rows = GrowthEvidence::query()->where('crm_contact_id', $contact->id)->orderBy('occurred_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (GrowthEvidence $e) => self::present($e))->values()]);
    }

    /** @return array<string, mixed> */
    public static function present(GrowthEvidence $e): array
    {
        $fields = (array) ($e->provider_fields['referral'] ?? []);

        return [
            'id' => $e->id,
            'type' => $e->evidence_type,
            'source' => $e->source,
            'label' => $e->evidence_type === GrowthEvidence::TYPE_META_CTWA ? MetaReferral::label($e->provider_fields['platform'] ?? null) : $e->source,
            'confidence' => $e->confidence,
            'conflict' => $e->conflict,
            'occurred_at' => $e->occurred_at->toIso8601String(),
            'message_id' => $e->message_id,
            'platform' => $e->provider_fields['platform'] ?? null,
            'platform_proof' => $e->provider_fields['platform_proof'] ?? null,
            'details' => array_filter([
                'headline' => $fields['headline'] ?? null,
                'body' => isset($fields['body']) ? mb_substr((string) $fields['body'], 0, 280) : null,
                'source_type' => $fields['source_type'] ?? null,
                'media_type' => $fields['media_type'] ?? null,
                'source_url' => $fields['source_url'] ?? null,
                'ad_id' => self::mask($fields['source_id'] ?? null),
                'click_id' => self::mask($fields['ctwa_clid'] ?? null),
            ], fn ($v) => $v !== null),
        ];
    }

    /** "••••1234": enough to compare two records, never the whole ID. */
    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return '••••'.mb_substr($value, -4);
    }
}
