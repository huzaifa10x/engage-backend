<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Access\SystemRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Enums\DateFormat;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Timeline\Timeline;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Batch 6, item 1: the workspace's date format and time zone, two separate settings. */
final class WorkspaceDatesTest extends TestCase
{
    private const SPA = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    private function register(?string $country, ?string $timezone, string $email): Tenant
    {
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/register', array_filter([
            'name' => 'Jane Doe', 'email' => $email, 'password' => 'Secret123456', 'password_confirmation' => 'Secret123456',
            'company_name' => 'Acme '.$email, 'country' => $country, 'timezone' => $timezone,
        ]))->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->tenantContext()->bypass(fn () => Tenant::query()->where('billing_email', $email)->sole());
    }

    public function test_both_are_suggested_from_the_country_when_a_workspace_is_created(): void
    {
        $uae = $this->register('AE', null, 'uae@example.com');
        $this->assertSame([DateFormat::DayMonthYear, 'Asia/Dubai'], [$uae->date_format, $uae->timezone]);

        $us = $this->register('US', 'America/Chicago', 'us@example.com');
        $this->assertSame([DateFormat::MonthDayYear, 'America/Chicago'], [$us->date_format, $us->timezone], "the browser's zone when it is one of the country's");

        $us2 = $this->register('US', 'Asia/Dubai', 'us2@example.com');
        $this->assertSame(DateFormat::MonthDayYear, $us2->date_format);
        $this->assertNotSame('Asia/Dubai', $us2->timezone, 'a zone from another country is not used');

        $jp = $this->register('JP', null, 'jp@example.com');
        $this->assertSame([DateFormat::YearMonthDay, 'Asia/Tokyo'], [$jp->date_format, $jp->timezone]);

        $none = $this->register(null, null, 'none@example.com');
        $this->assertSame([DateFormat::DayMonthYear, 'Asia/Dubai'], [$none->date_format, $none->timezone], 'defaults');
    }

    public function test_the_owner_changes_either_at_any_time_and_each_change_is_audited(): void
    {
        $tenant = $this->createTenant(['timezone' => 'Asia/Dubai']);
        $this->actingAsMember($this->addMember($tenant));

        $this->getJson('/api/v1/tenant')->assertOk()->assertJsonPath('data.date_format', 'dmy');
        $this->patchJson('/api/v1/tenant', ['date_format' => 'dd/mm'])->assertStatus(422);
        $this->patchJson('/api/v1/tenant', ['date_format' => 'mdy'])->assertOk()->assertJsonPath('data.date_format', 'mdy')->assertJsonPath('data.timezone', 'Asia/Dubai');
        $this->patchJson('/api/v1/tenant', ['timezone' => 'Europe/London'])->assertOk()->assertJsonPath('data.date_format', 'mdy');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.dates', ['timezone' => 'Europe/London', 'format' => 'mdy']);

        $audit = fn (string $action) => $this->tenantContext()->run($tenant, fn () => AuditLog::query()->where('action', $action)->sole());
        $this->assertSame(['date_format' => 'dmy'], $audit('tenant.date_format_changed')->getAttribute('before'));
        $this->assertSame(['date_format' => 'mdy'], $audit('tenant.date_format_changed')->getAttribute('after'));
        $this->assertSame(['timezone' => 'Europe/London'], $audit('tenant.timezone_changed')->getAttribute('after'));
    }

    public function test_an_agent_cannot_change_them(): void
    {
        $tenant = $this->createTenant();
        $this->addMember($tenant);
        $this->actingAsMember($this->addMember($tenant, SystemRole::Agent));
        $this->patchJson('/api/v1/tenant', ['date_format' => 'mdy'])->assertForbidden();
    }

    public function test_changing_the_time_zone_changes_only_how_times_are_shown(): void
    {
        $tenant = $this->createTenant(['timezone' => 'Asia/Dubai']);
        $this->actingAsMember($this->addMember($tenant));
        $contact = (string) $this->postJson('/api/v1/contacts', ['phone' => '+971501112233', 'name' => 'Omar'])->assertCreated()->json('data.id');
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 11, 18)->setTime(6, 0, 0));
        $this->tenantContext()->run($tenant, fn () => Timeline::record($contact, 'enquiry.started', Timeline::SYSTEM));
        $stored = fn () => $this->tenantContext()->run($tenant, fn () => DB::table('timeline_events')->where('contact_id', $contact)->where('type', 'enquiry.started')->value('occurred_at'));
        $before = $stored();

        $this->patchJson('/api/v1/tenant', ['timezone' => 'Europe/London'])->assertOk();

        $this->assertSame($before, $stored(), 'the moment it happened is unchanged (stored in UTC)');
        $this->assertStringStartsWith('2026-11-18 06:00:00', (string) $before);
    }
}
