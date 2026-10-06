<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Plans\Enums\PlanVersionStatus;
use App\Domain\Plans\FeatureKey;
use App\Domain\Plans\Models\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Plans\Models\PlanVersion;
use App\Domain\Plans\Models\PlanVersionFeature;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Plan catalog v1 — Implementation Blueprint v2, "Full feature matrix" ($29 / $79 / $139).
 * Prices in USD minor units; annual = 2 months free (monthly × 10). Enterprise = custom:
 * "Custom" cells seed as unlimited and are narrowed per contract with Super Admin overrides.
 * Stored contacts are uncapped on every plan, so there is deliberately no contacts limit.
 *
 * Idempotent: features and plans are upserted; a plan's v1 is created only if the plan has no
 * versions yet. Published versions are NEVER edited here — change pricing/limits by adding v2.
 *
 * Matrix values: int = limit, U = unlimited, false = not included, true = included,
 * array = included with config (e.g. ['level' => 'basic']).
 */
final class PlanCatalogSeeder extends Seeder
{
    private const U = 'unlimited';

    public function run(): void
    {
        DB::transaction(function (): void {
            $features = $this->seedFeatures();

            foreach ($this->plans() as $sort => $definition) {
                $plan = Plan::query()->updateOrCreate(['key' => $definition['key']], [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_public' => true,
                    'sort_order' => $sort,
                ]);

                if ($plan->versions()->exists()) {
                    // A feature added to the catalog after a version was published: give every existing
                    // version of this plan its default, so no plan silently loses (or gains) it.
                    foreach ($plan->versions()->get() as $existing) {
                        foreach (FeatureKey::cases() as $key) {
                            if (! PlanVersionFeature::query()->where('plan_version_id', $existing->getKey())->where('feature_id', $features[$key->value]->getKey())->exists()) {
                                $this->attach($existing, $features[$key->value], $definition['features'][$key->value] ?? false);
                            }
                        }
                    }

                    continue;
                }

                $version = PlanVersion::query()->create([
                    'plan_id' => $plan->getKey(),
                    'version' => 1,
                    'status' => PlanVersionStatus::Active,
                    'price_monthly_minor' => $definition['monthly'],
                    'price_yearly_minor' => $definition['monthly'] !== null ? $definition['monthly'] * 10 : null,
                    'currency' => 'USD',
                    'trial_days' => $definition['key'] === config('engage.plans.trial_plan') ? (int) config('engage.plans.trial_days') : 0,
                    'published_at' => now(),
                ]);

                foreach (FeatureKey::cases() as $key) {
                    $this->attach($version, $features[$key->value], $definition['features'][$key->value] ?? false);
                }
            }
        });
    }

    private function attach(PlanVersion $version, Feature $feature, mixed $value): void
    {
        // '__disabled' marks "not included, but carries config" (e.g. Web chatbot as a paid add-on).
        $disabled = $value === false || (is_array($value) && ($value['__disabled'] ?? false));
        $config = is_array($value) ? array_diff_key($value, ['__disabled' => true]) : null;

        PlanVersionFeature::query()->create([
            'plan_version_id' => $version->getKey(),
            'feature_id' => $feature->getKey(),
            'enabled' => ! $disabled,
            'limit_value' => is_int($value) ? $value : null,
            'config' => $config ?: null,
        ]);
    }

    /** @return array<string, Feature> */
    private function seedFeatures(): array
    {
        $features = [];

        foreach (FeatureKey::cases() as $key) {
            $features[$key->value] = Feature::query()->updateOrCreate(['key' => $key->value], [
                'name' => $key->label(),
                'type' => $key->type(),
                'unit' => $key->unit(),
            ]);
        }

        return $features;
    }

    /**
     * Blueprint v2 matrix. Order of each row: free, starter, growth, pro, enterprise.
     *
     * @return list<array{key: string, name: string, description: string, monthly: ?int, features: array<string, mixed>}>
     */
    private function plans(): array
    {
        $U = self::U;

        $matrix = [
            // Capacity
            'whatsapp_numbers' => [1, 1, 3, 5, $U],
            'team_seats' => [1, 2, 5, 15, $U],
            'canned_responses' => [5, 20, 100, $U, $U],
            'message_templates' => [3, $U, $U, $U, $U],
            'saved_segments' => [false, 3, 10, $U, $U],
            'tags' => [5, 25, 100, $U, $U],
            'custom_fields' => [2, 10, 25, $U, $U],
            'whatsapp_flows' => [false, 1, 5, $U, $U],
            'chatbots' => [false, 1, 5, $U, $U],
            'api_rate_limit_per_minute' => [120, 300, 600, 1200, 3000],
            'campaign_send_rate_per_hour' => [false, 500, 2000, 5000, 10000],
            // Per connected number. A WhatsApp Business app (coexistence) number is always capped at
            // 20 by SendThroughput, whatever the plan says here.
            'messages_per_second' => [20, 20, 30, 40, 40],
            'media_storage_mb' => [250, 1024, 5120, 20480, $U],
            'audit_log_retention_days' => [30, 90, 180, 365, $U],
            // Monthly metered levers
            'campaign_reach_monthly' => [false, 5000, 25000, 100000, $U],
            'automation_executions_monthly' => [false, 1000, 5000, 20000, $U],
            // Inbox
            'coexistence' => [['history' => true], ['history' => true], ['history' => true], ['history' => true], ['history' => true]],
            'conversation_assignment' => [false, ['level' => 'basic'], ['level' => 'standard'], ['level' => 'advanced'], ['level' => 'custom']],
            'internal_notes' => [false, true, true, true, true],
            'snooze' => [false, true, true, true, true],
            'business_hours' => [false, true, true, true, ['level' => 'custom']],
            'auto_routing' => [false, false, ['level' => 'basic'], ['level' => 'advanced'], ['level' => 'custom']],
            'ticketing' => [false, false, true, true, true],
            'csat' => [false, false, false, true, true],
            // Campaigns & contacts
            'broadcasts' => [false, true, true, true, true],
            'campaign_scheduling' => [false, true, true, true, true],
            'campaign_retry' => [false, false, true, true, true],
            'click_tracking' => [false, false, ['level' => 'basic'], ['level' => 'advanced'], ['level' => 'advanced']],
            'segments' => [false, ['level' => 'basic'], ['level' => 'basic'], ['level' => 'advanced'], ['level' => 'advanced']],
            // Automation
            'automations' => [false, ['level' => 'basic'], ['level' => 'full'], ['level' => 'full'], ['level' => 'custom']],
            'web_chatbot' => [['__disabled' => true, 'addon' => true], ['__disabled' => true, 'addon' => true], ['__disabled' => true, 'addon' => true], ['__disabled' => true, 'addon' => true], true],
            // Insights & health
            'analytics' => [['level' => 'basic'], ['level' => 'standard'], ['level' => 'standard'], ['level' => 'advanced'], ['level' => 'advanced', 'exports' => true]],
            'agent_reports' => [false, ['level' => 'basic'], ['level' => 'standard'], ['level' => 'advanced'], ['level' => 'advanced']],
            'number_health' => [['level' => 'basic'], true, true, true, true],
            'lead_source_tracking' => [['level' => 'basic'], true, true, true, true],
            'qr_generator' => [true, true, true, true, true],
            // Platform
            'rbac' => [['__disabled' => true, 'level' => 'owner_only'], ['level' => 'basic'], ['level' => 'full'], ['level' => 'full'], ['level' => 'full', 'sso' => true]],
            'integrations' => [false, ['level' => 'basic'], ['level' => 'standard'], ['level' => 'all'], ['level' => 'custom']],
            'api_access' => [false, false, false, true, ['tier' => 'high']],
            'webhooks' => [false, false, false, true, true],
            'compliance' => [true, true, true, true, ['custom_retention' => true]],
            'support' => [
                ['level' => 'community'],
                ['level' => 'email', 'sla' => '1 business day'],
                ['level' => 'email', 'sla' => '8 business hours'],
                ['level' => 'priority', 'sla' => '4 business hours'],
                ['level' => 'dedicated_csm', 'sla' => 'custom'],
            ],
            'sso' => [false, false, false, false, true],
            'white_label' => [false, false, false, false, true],
            'sub_accounts' => [false, false, false, false, true],
        ];

        $column = fn (int $i): array => array_map(fn (array $row) => $row[$i], $matrix);

        return [
            ['key' => 'free', 'name' => 'Free', 'monthly' => 0, 'features' => $column(0),
                'description' => 'Solo operator trying WhatsApp properly — a real shared inbox, never crippled.'],
            ['key' => 'starter', 'name' => 'Starter', 'monthly' => 2900, 'features' => $column(1),
                'description' => 'Small business that wants to broadcast, automate basics, and look professional.'],
            ['key' => 'growth', 'name' => 'Growth', 'monthly' => 7900, 'features' => $column(2),
                'description' => 'Scaling team adding numbers, advanced automation, and higher campaign reach.'],
            ['key' => 'pro', 'name' => 'Pro', 'monthly' => 13900, 'features' => $column(3),
                'description' => 'Multi-number operation needing API, webhooks, ticketing, and advanced analytics.'],
            ['key' => 'enterprise', 'name' => 'Enterprise', 'monthly' => null, 'features' => $column(4),
                'description' => 'Large orgs and agencies needing SSO/SAML, custom retention, SLA, and a dedicated CSM.'],
        ];
    }
}
