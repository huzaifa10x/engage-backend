<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** The Graph API version is one config value (META_GRAPH_VERSION), v27.0 by default, used by every call. */
final class GraphVersionTest extends TestCase
{
    use InteractsWithWhatsapp;

    public function test_v27_is_the_default_and_every_call_uses_the_configured_version(): void
    {
        $this->assertSame('v27.0', GraphClient::fromConfig()->version());

        $this->configureMeta();
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'pro');
        $owner = $this->addMember($tenant);
        $this->connectNumber($tenant);
        $waba = $this->tenantContext()->run($tenant, fn () => WabaAccount::query()->firstOrFail());
        $this->actingAsMember($owner);

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [], 'id' => '102290129340398'])]);
        $this->postJson("/api/v1/whatsapp/accounts/{$waba->id}/refresh")->assertOk();
        $this->postJson('/api/v1/whatsapp/signups')->assertJsonPath('data.launch.graph_version', 'v27.0'); // the Facebook SDK in the browser too

        $paths = Http::recorded()->map(fn (array $pair) => (string) parse_url($pair[0]->url(), PHP_URL_PATH))->all();
        $this->assertNotEmpty($paths);
        foreach ($paths as $path) {
            $this->assertStringStartsWith('/v27.0/', $path);
        }

        // Rolling back is a config change only.
        config(['engage.meta.graph_version' => 'v26.0']);
        $this->app->forgetInstance(GraphClient::class);
        $this->assertSame('v26.0', app(GraphClient::class)->version());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v27.0/102290129340398'));
    }
}
