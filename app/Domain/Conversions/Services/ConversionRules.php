<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\Models\ConversionRule;
use Illuminate\Support\Facades\Cache;

/**
 * The workspace's conversion rules. Which tags and flow steps count, and whether linked orders
 * count, is cached per workspace for a minute (cleared on every change), so a contact save or a
 * flow step costs no query when nothing is watched.
 */
final class ConversionRules
{
    public static function key(string $tenantId): string
    {
        return "conversions:watched:{$tenantId}";
    }

    /** @return array{tags: list<string>, steps: list<string>, orders: bool} */
    public static function watched(string $tenantId): array
    {
        return Cache::remember(self::key($tenantId), 60, function (): array {
            $rules = ConversionRule::query()->where('enabled', true)->get(['trigger_type', 'trigger_ref']);

            return [
                'tags' => $rules->where('trigger_type', ConversionRule::TAG)->pluck('trigger_ref')->map(fn ($t) => mb_strtolower((string) $t))->values()->all(),
                'steps' => $rules->where('trigger_type', ConversionRule::FLOW_STEP)->pluck('trigger_ref')->map(fn ($s) => (string) $s)->values()->all(),
                'orders' => $rules->where('trigger_type', ConversionRule::ORDER)->isNotEmpty(),
            ];
        });
    }

    /** A tag added to a contact counts as a lead. */
    public function leadOnTag(string $tag, bool $enabled = true): ConversionRule
    {
        return $this->save(ConversionRule::TAG, mb_strtolower(trim($tag)), ConversionRule::LEAD, $enabled);
    }

    /** Reaching a step of a flow counts as a lead. */
    public function leadOnFlowStep(string $flowId, string $stepId, bool $enabled = true): ConversionRule
    {
        return $this->save(ConversionRule::FLOW_STEP, "{$flowId}:{$stepId}", ConversionRule::LEAD, $enabled);
    }

    /** A paid WooCommerce order linked to a chat counts as a purchase. */
    public function purchasesFromOrders(bool $enabled = true): ConversionRule
    {
        return $this->save(ConversionRule::ORDER, null, ConversionRule::PURCHASE, $enabled);
    }

    private function save(string $type, ?string $ref, string $event, bool $enabled): ConversionRule
    {
        $rule = ConversionRule::query()->updateOrCreate(['trigger_type' => $type, 'trigger_ref' => $ref], ['event_name' => $event, 'enabled' => $enabled]);
        Cache::forget(self::key($rule->tenant_id));

        return $rule;
    }
}
