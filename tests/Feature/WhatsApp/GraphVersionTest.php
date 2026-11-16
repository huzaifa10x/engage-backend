<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * The Graph API version is one config value (META_GRAPH_VERSION). Every call to Meta carries it in
 * its path, so none falls back to the app's default version in the App Dashboard, and going back
 * to v26.0 is a config change only.
 */
final class GraphVersionTest extends TestCase
{
    use InteractsWithWhatsapp;

    /** @return array<string, array{string}> */
    public static function versions(): array
    {
        return ['v27.0 (now)' => ['v27.0'], 'v26.0 (rollback)' => ['v26.0']];
    }

    /** @return list<string> the path of every request sent */
    private function paths(): array
    {
        return Http::recorded()->map(fn (array $pair) => (string) parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
    }

    #[DataProvider('versions')]
    public function test_every_graph_call_we_make_uses_the_configured_version(string $version): void
    {
        config(['engage.meta.graph_version' => $version, 'engage.meta.app_id' => '1234567890', 'engage.meta.app_secret' => 'secret']);
        $this->app->forgetInstance(GraphClient::class);
        $graph = app(GraphClient::class);
        $this->assertSame($version, $graph->version());

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'access_token' => 'EAA-new', 'data' => [], 'success' => true, 'id' => 'upload:1?sig=abc', 'h' => 'handle',
            'messages' => [['id' => 'wamid.1']], 'contacts' => [['wa_id' => '971501234567']], 'request_id' => 'r1', 'url' => 'https://lookaside.example/x',
        ])]);

        // Every endpoint the app calls: signup, token check, webhooks, numbers, messages, media, templates, Marketing Messages.
        $calls = [
            'token exchange' => fn () => $graph->exchangeCode('code'),
            'debug_token' => fn () => $graph->debugToken('EAA-x'),
            'subscribe webhooks' => fn () => $graph->subscribeApp('102290129340398', 't'),
            'list subscribed apps' => fn () => $graph->listSubscribedApps('102290129340398', 't'),
            'unsubscribe webhooks' => fn () => $graph->unsubscribeApp('102290129340398', 't'),
            'register' => fn () => $graph->registerPhoneNumber('106540352242922', '123456', 't'),
            'deregister' => fn () => $graph->deregisterPhoneNumber('106540352242922', 't'),
            'coexistence sync' => fn () => $graph->requestSmbAppData('106540352242922', 'history', 't'),
            'send message' => fn () => $graph->sendMessage('106540352242922', ['to' => '971501234567', 'type' => 'text', 'text' => ['body' => 'Hi']], 't'),
            'send marketing message' => fn () => $graph->sendMarketingMessage('106540352242922', ['to' => '971501234567', 'type' => 'template', 'template' => ['name' => 'x', 'language' => ['code' => 'en']]], 't'),
            'mark read' => fn () => $graph->markRead('106540352242922', 'wamid.1', 't'),
            'upload media' => fn () => $graph->uploadMedia('106540352242922', 'bytes', 'a.jpg', 'image/jpeg', 't'),
            'get media' => fn () => $graph->getMedia('555', 't'),
            'list templates' => fn () => $graph->listTemplates('102290129340398', 't'),
            'template sample upload' => fn () => $graph->uploadTemplateSample('bytes', 'a.jpg', 'image/jpeg', 't'),
            'create template' => fn () => $graph->createTemplate('102290129340398', ['name' => 'x', 'language' => 'en', 'category' => 'UTILITY', 'components' => []], 't'),
            'update template' => fn () => $graph->updateTemplate('900001', ['components' => []], 't'),
            'delete template' => fn () => $graph->deleteTemplate('102290129340398', 'x', '900001', 't'),
            'get WABA' => fn () => $graph->getWaba('102290129340398', 't'),
            'Marketing Messages status' => fn () => $graph->getMarketingMessagesStatus('102290129340398', 't'),
            'Marketing Messages onboarding' => fn () => $graph->requestMarketingMessagesOnboarding('1572475357101718', 't'),
            'get phone number' => fn () => $graph->getPhoneNumber('106540352242922', 't'),
            'list phone numbers' => fn () => $graph->listPhoneNumbers('102290129340398', 't'),
        ];
        foreach ($calls as $name => $call) {
            $before = count($this->paths());
            $call();
            $this->assertGreaterThan($before, count($this->paths()), "{$name} made a call");
        }

        foreach ($this->paths() as $path) {
            $this->assertStringStartsWith("/{$version}/", $path, 'never unversioned, never another version');
        }
    }

    #[DataProvider('versions')]
    public function test_the_app_and_the_signup_popup_follow_the_configured_version(string $version): void
    {
        $this->configureMeta();
        config(['engage.meta.graph_version' => $version]);
        $this->app->forgetInstance(GraphClient::class);
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'pro');
        $owner = $this->addMember($tenant);
        $this->connectNumber($tenant);
        $waba = $this->tenantContext()->run($tenant, fn () => WabaAccount::query()->firstOrFail());
        $this->actingAsMember($owner);

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [], 'id' => '102290129340398'])]);
        $this->postJson("/api/v1/whatsapp/accounts/{$waba->id}/refresh")->assertOk();
        // The Facebook SDK in the browser (Embedded Signup) is started with the same version.
        $this->postJson('/api/v1/whatsapp/signups')->assertJsonPath('data.launch.graph_version', $version);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), "/{$version}/102290129340398"));
        foreach ($this->paths() as $path) {
            $this->assertStringStartsWith("/{$version}/", $path);
        }
    }

    public function test_an_empty_or_malformed_version_never_sends_an_unversioned_call(): void
    {
        foreach (['', '  ', null, '27.0', 'latest', 'v27'] as $bad) {
            $this->assertSame(GraphClient::DEFAULT_VERSION, GraphClient::versionFrom($bad), var_export($bad, true));
        }
        $this->assertSame('v27.0', GraphClient::DEFAULT_VERSION);
        $this->assertSame('v26.0', GraphClient::versionFrom(' v26.0 '));
    }
}
