<?php

declare(strict_types=1);

namespace Tests\Feature\Conversions;

use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Conversions\Models\MetaDataset;
use App\Domain\Conversions\Services\ConversionReporting;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Conversions to Meta, 3a: the account's dataset, behind the workspace flag and Meta's permission. */
final class MetaDatasetsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private WabaAccount $waba;

    /** @var list<string> */
    private array $scopes = ['whatsapp_business_management', 'whatsapp_business_messaging', 'whatsapp_business_manage_events'];

    private bool $datasetRefused = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->connectNumber($this->tenant);
        $this->waba = $this->tenantContext()->run($this->tenant, fn () => WabaAccount::query()->firstOrFail());

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $r) {
            if (str_contains($r->url(), 'debug_token')) {
                return Http::response(['data' => ['is_valid' => true, 'type' => 'SYSTEM_USER', 'scopes' => $this->scopes,
                    'granular_scopes' => [['scope' => 'whatsapp_business_manage_events', 'target_ids' => ['102290129340398']]]]]);
            }
            if ($this->datasetRefused) {
                return Http::response(['error' => ['message' => '(#200) Permission denied', 'type' => 'OAuthException', 'code' => 200]], 403);
            }

            return Http::response(['id' => '1503923451234567']); // Meta returns the same dataset every time
        }]);
    }

    private function enable(): string
    {
        return $this->tenantContext()->run($this->tenant, fn () => app(ConversionReporting::class)->enable($this->tenant, $this->waba));
    }

    /** @return list<MetaDataset> */
    private function datasets(): array
    {
        return $this->tenantContext()->bypass(fn () => MetaDataset::query()->get()->all());
    }

    public function test_a_workspace_without_the_flag_gets_nothing_and_meta_is_not_called(): void
    {
        $this->assertSame(ConversionReporting::NOT_ALLOWED, $this->enable());
        $this->assertSame([], $this->datasets());
        Http::assertNothingSent();
    }

    public function test_with_the_flag_and_the_permission_the_dataset_is_created_with_the_clients_token_and_saved_once(): void
    {
        $this->tenantContext()->run($this->tenant, fn () => ConversionsAccess::set($this->tenant, true));

        $this->assertSame(ConversionReporting::ENABLED, $this->enable());
        $this->assertSame(ConversionReporting::ENABLED, $this->enable()); // Meta returns the existing dataset

        $datasets = $this->datasets();
        $this->assertCount(1, $datasets);
        $this->assertSame(['102290129340398', '1503923451234567', true], [$datasets[0]->waba_id, $datasets[0]->dataset_id, $datasets[0]->enabled]);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/102290129340398/dataset')
            && $r->header('Authorization')[0] === 'Bearer EAA-existing-token');
        $this->assertTrue($this->tenantContext()->bypass(fn () => $this->waba->fresh()->events_permission));

        // Off keeps the dataset ID for later; on again uses it.
        $this->tenantContext()->run($this->tenant, fn () => app(ConversionReporting::class)->disable($this->waba));
        $this->assertFalse($this->datasets()[0]->enabled);
        $this->assertNull($this->tenantContext()->run($this->tenant, fn () => app(ConversionReporting::class)->datasetFor('102290129340398')));
    }

    public function test_without_the_permission_nothing_is_saved_and_the_account_shows_not_enabled(): void
    {
        $this->tenantContext()->run($this->tenant, fn () => ConversionsAccess::set($this->tenant, true));
        $this->scopes = ['whatsapp_business_management', 'whatsapp_business_messaging'];

        $this->assertSame(ConversionReporting::NOT_ENABLED, $this->enable());
        $this->assertSame([], $this->datasets());
        $this->assertFalse($this->tenantContext()->bypass(fn () => $this->waba->fresh()->events_permission));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/dataset'));

        // The token says yes but Meta refuses the dataset call: still nothing saved.
        $this->scopes[] = 'whatsapp_business_manage_events';
        $this->datasetRefused = true;
        $this->assertSame(ConversionReporting::NOT_ENABLED, $this->enable());
        $this->assertSame([], $this->datasets());
        $this->assertFalse($this->tenantContext()->bypass(fn () => $this->waba->fresh()->events_permission));
    }

    public function test_the_platform_command_switches_a_workspace_on_and_turns_reporting_on(): void
    {
        $this->artisan('engage:conversions', ['--enable' => '102290129340398'])->expectsOutputToContain('Not allowed')->assertSuccessful();
        $this->artisan('engage:conversions', ['--allow' => '102290129340398'])->expectsOutputToContain('Allowed: ')->assertSuccessful(); // by its WhatsApp account
        $this->artisan('engage:conversions', ['--enable' => '102290129340398'])->expectsOutputToContain('Reporting on for 102290129340398: dataset 1503923451234567.')->assertSuccessful();
        $this->artisan('engage:conversions', ['--workspace' => $this->tenant->id, '--lead-tag' => 'Booked', '--purchases' => 'on'])->assertSuccessful();
        $this->artisan('engage:conversions', ['--status' => true])->expectsOutputToContain('reporting on (dataset 1503923451234567)')
            ->expectsOutputToContain('rule LeadSubmitted on tag booked')->expectsOutputToContain('rule Purchase on order')->assertSuccessful();
    }
}
