<?php

declare(strict_types=1);

namespace Tests\Feature\Chatbots;

use App\Domain\Developer\Services\UrlGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsChatbots;
use Tests\TestCase;

/**
 * Live integration tests against unsafe destinations: redirects are never followed, a listed test
 * endpoint whose name resolves to an internal address is still refused, the connection is pinned
 * to the checked address, and one workspace's test endpoints never apply to another.
 */
final class ChatbotLiveTestSecurityTest extends TestCase
{
    use BuildsChatbots;

    /** @var list<string> */
    private array $called = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootChatbots();
        // sandbox.example.com is public; rebind.example.com resolves to an internal address.
        $this->app->bind(UrlGuard::class, fn () => new class extends UrlGuard
        {
            protected function resolve(string $host): array
            {
                return $host === 'rebind.example.com' ? ['10.0.0.5'] : ['93.184.216.34'];
            }
        });
        Http::fake([
            'sandbox.example.com/redirect*' => function (Request $r) {
                $this->called[] = $r->url();

                return Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']);
            },
            '169.254.169.254/*' => function (Request $r) {
                $this->called[] = $r->url();

                return Http::response(['secret' => 'leaked']);
            },
            // Laravel runs every stub for every request; this one records only what the others don't.
            '*' => function (Request $r) {
                if (! str_contains($r->url(), 'sandbox.example.com/redirect') && ! str_contains($r->url(), '169.254.169.254')) {
                    $this->called[] = $r->url();
                }

                return Http::response(['ok' => true]);
            },
        ]);
    }

    private function flowCalling(string $url): string
    {
        $this->actingAsMember($this->owner);
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Bot', 'phone_number_id' => $this->number->id, 'template' => 'blank'])->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => ['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'api']),
            $this->node('api', 'http', ['method' => 'POST', 'url' => $url, 'body' => '{"x":1}'], ['success' => 'ok', 'error' => 'bad']),
            $this->node('ok', 'message', ['text' => 'Saved'], ['next' => 'team']),
            $this->node('bad', 'message', ['text' => 'Failed'], ['next' => 'team']),
            $this->node('team', 'handoff', ['text' => 'A person will reply.']),
        ]]])->assertOk();

        return $id;
    }

    /** @return list<string> */
    private function liveTest(string $id): array
    {
        $t = $this->postJson("/api/v1/chatbots/{$id}/test", ['integrations' => 'live', 'confirm_live' => true, 'live_reason' => 'Security check'])->assertOk()->json('data.transcript');

        return collect($t)->map(fn ($e) => $e['message']['body'] ?? $e['text'] ?? '')->all();
    }

    public function test_a_redirect_from_a_test_endpoint_is_never_followed(): void
    {
        $id = $this->flowCalling('https://sandbox.example.com/redirect');
        $this->putJson('/api/v1/chatbots/workspace', ['test_endpoints' => ['https://sandbox.example.com/']])->assertOk();
        $lines = $this->liveTest($id);

        $this->assertSame(['https://sandbox.example.com/redirect'], $this->called, 'called once (a 3xx is not retried), and the redirect target is never requested');
        $this->assertContains('Failed', $lines, 'a 3xx takes the Error branch');
    }

    public function test_a_listed_endpoint_that_resolves_to_an_internal_address_is_refused(): void
    {
        $id = $this->flowCalling('https://rebind.example.com/hook');
        $this->putJson('/api/v1/chatbots/workspace', ['test_endpoints' => ['https://rebind.example.com/']])->assertOk();
        $lines = $this->liveTest($id);

        $this->assertSame([], $this->called);
        $this->assertStringContainsString('not reachable from the internet', implode("\n", $lines));
        $this->assertSame(0, $this->inTenant(fn () => DB::table('audit_log')->where('action', 'chatbot.test_live_call')->count()), 'nothing was called, nothing to record');
    }

    public function test_another_workspaces_test_endpoints_never_apply(): void
    {
        $this->actingAsMember($this->owner);
        $this->putJson('/api/v1/chatbots/workspace', ['test_endpoints' => ['https://sandbox.example.com/']])->assertOk();
        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->tenant = $other;
        $this->owner = $this->addMember($other);
        $this->number = $this->connectNumber($other, wabaId: '202290129340399', phoneNumberId: '206540352242923', display: '+971 58 000 0002');
        $id = $this->flowCalling('https://sandbox.example.com/hook');

        $this->postJson("/api/v1/chatbots/{$id}/test", ['integrations' => 'live', 'confirm_live' => true, 'live_reason' => 'Security check'])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['integrations']]]]);
        $this->assertSame([], $this->called);
    }

    public function test_internal_and_special_ranges_are_not_public_and_connections_are_pinned(): void
    {
        foreach (['127.0.0.1', '10.1.2.3', '169.254.169.254', '100.64.0.1', '198.18.0.1', '192.0.0.10', '::1', '::ffff:10.0.0.1', 'fd00::1', '64:ff9b::a00:1', '2002:a00:1::1'] as $ip) {
            $this->assertFalse(UrlGuard::isPublicIp($ip), $ip);
        }
        foreach (['93.184.216.34', '8.8.8.8', '2606:4700::1111'] as $ip) {
            $this->assertTrue(UrlGuard::isPublicIp($ip), $ip);
        }
        $this->assertSame(['sandbox.example.com:443:93.184.216.34'], UrlGuard::pin('https://Sandbox.example.com/x', ['93.184.216.34']));
        $this->assertSame(['api.example.com:443:[2606:4700::1111]'], UrlGuard::pin('https://api.example.com/', ['2606:4700::1111']));
    }
}
