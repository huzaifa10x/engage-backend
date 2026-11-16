<?php

declare(strict_types=1);

namespace Tests\Feature\Conversions;

use App\Domain\Identity\Enums\PlatformRole;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Conversions to Meta, 3b: platform monitoring in Super Admin, and the Sentry alerts. */
final class ConversionMonitoringTest extends TestCase
{
    private const DSN = 'https://publickey123@o4501.ingest.de.sentry.io/4507001';

    private function event(Tenant $tenant, string $status, array $extra = []): void
    {
        $this->tenantContext()->bypass(fn () => DB::table('conversion_events')->insert($extra + [
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'event_name' => 'LeadSubmitted', 'event_time' => now()->subMinutes(30),
            'status' => $status, 'is_test' => false, 'created_at' => now()->subMinutes(30), 'updated_at' => now(),
            'sent_at' => $status === 'sent' ? now()->subMinutes(28) : null,
        ]));
    }

    /** A click reference (monitoring only looks at whether an event had one). */
    private function clickId(Tenant $tenant): string
    {
        return (string) Str::uuid7();
    }

    public function test_admins_see_success_rates_volume_click_id_share_delay_and_failures_by_reason(): void
    {
        $a = $this->createTenant(['name' => 'Team Dubai']);
        $b = $this->createTenant(['name' => 'Nova Fitness']);
        $click = $this->clickId($a);
        foreach (range(1, 9) as $i) {
            $this->event($a, 'sent', ['ad_click_id' => $click]);
        }
        $this->event($a, 'failed', ['ad_click_id' => $click, 'error_reason' => 'Invalid parameter (code 100)']);
        $this->event($a, 'sent', ['ad_click_id' => $click, 'is_test' => true]);
        $this->event($b, 'skipped', ['error_reason' => 'The chat did not start from a Meta ad (no click ID), so Meta cannot match it.']);
        $this->event($b, 'expired', ['ad_click_id' => $this->clickId($b), 'error_reason' => 'Not sent within 6 days.']);
        $this->event($b, 'sent', ['ad_click_id' => $click, 'created_at' => now()->subDays(20)]); // outside 15 days

        $this->actingAsAdmin(PlatformRole::Support);
        $this->get('/admin/conversions')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('conversions/Index')
            ->where('stats.success_rate', 0.8182)          // 9 sent ÷ (9 + 1 failed + 1 expired)
            ->where('stats.successful_events', 10)         // the test event counts as a successful call
            ->where('stats.click_id_share', 0.9167)        // 11 of 12 real conversions had a click ID
            ->where('stats.send_delay_seconds.p50', 120)
            ->where('stats.workspaces.0.name', 'Team Dubai')
            ->where('stats.workspaces.0.success_rate', 0.9)
            ->where('stats.workspaces.1.name', 'Nova Fitness')
            ->where('stats.workspaces.1.success_rate', 0)
            ->where('stats.reasons', fn ($r) => collect($r)->pluck('reason')->sort()->values()->all() === [
                'Invalid parameter (code 100)', 'Not sent within 6 days.', 'The chat did not start from a Meta ad (no click ID), so Meta cannot match it.',
            ]));

        $this->actingAsAdmin(PlatformRole::Finance);
        $this->get('/admin/conversions')->assertForbidden();
    }

    public function test_sentry_is_alerted_below_90_percent_and_for_events_queued_over_24_hours_once_every_six_hours(): void
    {
        config(['engage.sentry.dsn' => self::DSN, 'engage.sentry.sample_rate' => 1.0]);
        Http::fake(['*.sentry.io/*' => Http::response(['id' => 'x'])]);
        Cache::flush();
        $tenant = $this->createTenant();

        // Healthy: 19 sent, 1 failed (95%); one queued only 2 hours.
        foreach (range(1, 19) as $i) {
            $this->event($tenant, 'sent');
        }
        $this->event($tenant, 'failed');
        $this->event($tenant, 'queued', ['created_at' => now()->subHours(2)]);
        $this->artisan('engage:conversions:monitor')->expectsOutput('Conversions to Meta: no alerts.')->assertSuccessful();
        Http::assertNothingSent();

        // 19 sent, 3 failed (86%), and one queued for 25 hours.
        $this->event($tenant, 'failed');
        $this->event($tenant, 'expired');
        $this->event($tenant, 'queued', ['created_at' => now()->subHours(25)]);
        $this->artisan('engage:conversions:monitor')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_contains($r->body(), 'success rate 86.4% in the last 24 hours'));
        Http::assertSent(fn (Request $r) => str_contains($r->body(), '1 event(s) queued for more than 24 hours'));

        $this->artisan('engage:conversions:monitor')->assertSuccessful();
        Http::assertSentCount(2); // not again within six hours
    }

    public function test_too_few_events_never_alert_on_the_rate(): void
    {
        config(['engage.sentry.dsn' => self::DSN]);
        Http::fake();
        $tenant = $this->createTenant();
        $this->event($tenant, 'failed');
        $this->event($tenant, 'sent');
        $this->artisan('engage:conversions:monitor')->expectsOutput('Conversions to Meta: no alerts.');
        Http::assertNothingSent();
    }
}
