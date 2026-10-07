<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Developer\ApiSpec;
use App\Domain\Developer\Services\UrlGuard;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Keeps the documentation, the Postman collection and the API itself telling the same story.
 * If an endpoint is added, removed, renamed, re-scoped or changes shape without ApiSpec being
 * updated, one of these tests fails.
 */
final class ApiDocumentationTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const DESCRIPTION_ROUTES = ['spec', 'postman.json', 'openapi.json'];

    private Tenant $tenant;

    private TenantMembership $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        Storage::fake(config('filesystems.default'));
        $this->tenant = $this->createTenant(['name' => 'Palm Estates']);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->connectNumber($this->tenant);
        $this->app->bind(UrlGuard::class, fn () => new class extends UrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::fake([
            'graph.facebook.com/*' => fn () => Http::response(['id' => 'meta-media-1', 'messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.D'.bin2hex(random_bytes(6))]]]),
            'example.com/*' => Http::response(base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='), 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    private function key(): string
    {
        $this->actingAsMember($this->owner);
        $key = (string) $this->postJson('/api/v1/developer/api-keys', ['name' => 'docs', 'scopes' => ['messages:send', 'messages:read', 'contacts:read', 'contacts:write', 'templates:read']])->assertCreated()->json('data.key');
        $this->app['auth']->forgetGuards();

        return $key;
    }

    /** @return array<string, ?string> "METHOD /path" → the scope the route's middleware enforces */
    private function realRoutes(): array
    {
        $routes = [];
        /** @var LaravelRoute $route */
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/public/v1/')) {
                continue;
            }
            $path = '/'.substr($route->uri(), strlen('api/public/v1/'));
            if (in_array(ltrim($path, '/'), self::DESCRIPTION_ROUTES, true)) {
                continue;
            }
            $scope = null;
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, AuthenticateApiKey::class)) {
                    $scope = str_contains($middleware, ':') ? explode(':', $middleware, 2)[1] : null;
                }
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes["{$method} {$path}"] = $scope;
            }
        }
        ksort($routes);

        return $routes;
    }

    public function test_every_route_is_documented_with_the_permission_it_really_enforces(): void
    {
        $documented = [];
        foreach (ApiSpec::endpoints() as [$method, $path, $scope]) {
            $documented["{$method} {$path}"] = $scope;
        }
        ksort($documented);

        $this->assertSame(
            $this->realRoutes(),
            $documented,
            'The public API routes and ApiSpec disagree. Add, remove or correct the entry in app/Domain/Developer/ApiSpec.php so the docs and the Postman collection stay true.',
        );

        // Every permission in the docs is one a key can actually be given, and every error code listed for an endpoint is explained.
        $doc = ApiSpec::document();
        $scopes = array_column($doc['authentication']['scopes'], 'key');
        $codes = array_column($doc['errors']['codes'], 'code');
        foreach (ApiSpec::endpoints() as [, $path, $scope, $entry]) {
            $this->assertTrue($scope === null || in_array($scope, $scopes, true), "{$path}: unknown permission {$scope}");
            foreach ($entry['errors'] ?? [] as $code) {
                $this->assertContains($code, $codes, "{$path} lists the error {$code}, which the Errors section does not explain");
            }
        }
    }

    public function test_the_documented_examples_are_what_the_api_accepts_and_returns(): void
    {
        $key = $this->key();
        $api = fn () => $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json', 'Idempotency-Key' => bin2hex(random_bytes(8))]);
        $spec = collect(ApiSpec::endpoints())->mapWithKeys(fn (array $e) => [$e[3]['id'] => $e[3]]);
        // The real answer must contain every field the documented example shows (it may contain more).
        $sameShape = function (array $documented, array $actual, string $where): void {
            foreach (array_keys($documented) as $field) {
                $this->assertArrayHasKey($field, $actual, "{$where}: the documented field \"{$field}\" is missing from the real response");
            }
        };

        // Contacts: the documented request bodies, sent as they are.
        $created = $api()->postJson('/api/public/v1/contacts', $spec['contacts.create']['request']['example'])->assertStatus($spec['contacts.create']['response']['status']);
        $sameShape($spec['contacts.create']['response']['example']['data'], $created->json('data'), 'POST /contacts');
        $contactId = (string) $created->json('data.id');

        $updated = $api()->patchJson("/api/public/v1/contacts/{$contactId}", $spec['contacts.update']['request']['example'])->assertStatus($spec['contacts.update']['response']['status']);
        $sameShape($spec['contacts.update']['response']['example']['data'], $updated->json('data'), 'PATCH /contacts/{id}');
        $sameShape($spec['contacts.show']['response']['example']['data'], $api()->getJson("/api/public/v1/contacts/{$contactId}")->assertOk()->json('data'), 'GET /contacts/{id}');
        $list = $api()->getJson('/api/public/v1/contacts?phone=%2B971501234567')->assertOk();
        $sameShape($spec['contacts']['response']['example']['data'][0], $list->json('data.0'), 'GET /contacts');
        $this->assertArrayHasKey('next_cursor', $list->json());
        $api()->postJson("/api/public/v1/contacts/{$contactId}/opt-out", $spec['contacts.opt-out']['request']['example'])->assertOk()->assertJsonPath('data.consent', 'opted_out');
        $api()->postJson("/api/public/v1/contacts/{$contactId}/opt-in", $spec['contacts.opt-in']['request']['example'])->assertOk()->assertJsonPath('data.consent', 'opted_in');

        // Account.
        $sameShape($spec['me']['response']['example']['data'], $api()->getJson('/api/public/v1/me')->assertOk()->json('data'), 'GET /me');
        $sameShape($spec['phone-numbers']['response']['example']['data'][0], $api()->getJson('/api/public/v1/phone-numbers')->assertOk()->json('data.0'), 'GET /phone-numbers');
        $api()->getJson('/api/public/v1/templates?status=approved')->assertOk();

        // Messages: the customer writes first (opens the 24-hour window), then every documented free-form example is sent.
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(waId: '971501234567')))->assertOk();
        $this->app['auth']->forgetGuards();

        $sent = $api()->postJson('/api/public/v1/messages', $spec['messages.send']['request']['example'])->assertStatus($spec['messages.send']['response']['status']);
        $sameShape($spec['messages.send']['response']['example']['data'], $sent->json('data'), 'POST /messages');
        $sameShape($spec['messages.show']['response']['example']['data'], $api()->getJson('/api/public/v1/messages/'.$sent->json('data.id'))->assertOk()->json('data'), 'GET /messages/{id}');

        // Upload, then send the uploaded file and a file by URL exactly as documented.
        $upload = $api()->post('/api/public/v1/media', ['file' => UploadedFile::fake()->createWithContent('Invoice-1001.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF")])->assertStatus($spec['media.upload']['response']['status']);
        $sameShape($spec['media.upload']['response']['example']['data'], $upload->json('data'), 'POST /media');
        foreach ($spec['messages.send']['request']['more'] as $variant) {
            $body = $variant['example'];
            if ($body['type'] === 'template') {
                continue; // needs a template approved by WhatsApp; its fields are covered by the validation test below
            }
            if (isset($body['media_id'])) {
                $body['media_id'] = $upload->json('data.id');
            }
            if (isset($body['contact_id'])) {
                $body['contact_id'] = $contactId;
            }
            $media = $api()->postJson('/api/public/v1/messages', $body)->assertStatus(202)->assertJsonPath('data.type', $body['type']);
            $this->assertNotNull($media->json('data.media.id'), "{$variant['title']}: the message should carry its file");
        }

        $conversations = $api()->getJson('/api/public/v1/conversations?status=open')->assertOk();
        $sameShape($spec['conversations']['response']['example']['data'][0], $conversations->json('data.0'), 'GET /conversations');
        $messages = $api()->getJson('/api/public/v1/conversations/'.$conversations->json('data.0.id').'/messages')->assertOk();
        $sameShape($spec['conversations.messages']['response']['example']['data'][0], $messages->json('data.0'), 'GET /conversations/{id}/messages');
    }

    public function test_documented_fields_and_errors_are_real(): void
    {
        $key = $this->key();
        $api = fn () => $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json']);

        // The documented error format and codes.
        $doc = ApiSpec::document();
        $invalid = $api()->postJson('/api/public/v1/messages', ['type' => 'text'])->assertStatus(422);
        $this->assertEqualsCanonicalizing(array_keys($doc['errors']['format']['error']), array_keys($invalid->json('error')));
        $this->assertSame('validation_failed', $invalid->json('error.code'));
        $api()->postJson('/api/public/v1/messages', ['to' => '+971501234567', 'type' => 'text', 'text' => 'Hi'])->assertStatus(422)->assertJsonPath('error.code', 'window_closed');
        $api()->getJson('/api/public/v1/contacts/01a11637-76d8-7315-a1e6-a952a9445800')->assertStatus(404)->assertJsonPath('error.code', 'not_found');

        // The documented template body is accepted by validation (it fails later, on "no such approved template", not on its shape).
        $template = collect(ApiSpec::endpoints())->firstWhere(fn (array $e) => $e[3]['id'] === 'messages.send')[3]['request']['more'][0]['example'];
        $response = $api()->postJson('/api/public/v1/messages', $template);
        $this->assertNotContains('template', array_map(fn (string $f) => explode('.', $f)[0], array_keys((array) $response->json('error.details.fields'))), 'The documented template example is refused by validation.');

        // Media rules described in the docs.
        $api()->postJson('/api/public/v1/messages', ['to' => '+971501234567', 'type' => 'image'])->assertStatus(422);
        $api()->postJson('/api/public/v1/messages', ['to' => '+971501234567', 'type' => 'image', 'media_url' => 'http://example.com/a.jpg'])->assertStatus(422); // not https outside tests is refused; here: window closed
        $api()->post('/api/public/v1/media', ['file' => UploadedFile::fake()->createWithContent('run.exe', 'MZ'.str_repeat("\0", 64))])->assertStatus(422);
    }

    public function test_the_postman_collection_and_openapi_file_are_generated_from_the_same_description(): void
    {
        $endpoints = ApiSpec::endpoints();

        // Documentation data: public, complete, with live rate limits per plan.
        $spec = $this->getJson('/api/public/v1/spec')->assertOk();
        $this->assertSame(count($endpoints), collect($spec->json('data.groups'))->sum(fn (array $g) => count($g['endpoints'])));
        $this->assertSame(1200, collect($spec->json('data.rate_limits'))->firstWhere('plan', 'Pro')['requests_per_minute']);
        $this->assertCount(11, $spec->json('data.webhooks.events'));

        // Postman: importable v2.1, bearer auth through a variable, one request per endpoint (plus the extra examples).
        $postman = $this->get('/api/public/v1/postman.json')->assertOk()->assertHeader('content-disposition', 'attachment; filename="10x-engage-api.postman_collection.json"');
        $collection = $postman->json();
        $this->assertSame('https://schema.getpostman.com/json/collection/v2.1.0/collection.json', $collection['info']['schema']);
        $this->assertSame('{{api_key}}', $collection['auth']['bearer'][0]['value']);
        $this->assertStringEndsWith('/api/public/v1', collect($collection['variable'])->firstWhere('key', 'base_url')['value']);
        $requests = collect($collection['item'])->flatMap(fn (array $folder) => $folder['item']);
        $inCollection = $requests->map(fn (array $r) => $r['request']['method'].' '.preg_replace('/\{\{\w+\}\}/', '{id}', str_replace('{{base_url}}', '', $r['request']['url']['raw'])))->unique()->sort()->values()->all();
        $this->assertSame(collect($endpoints)->map(fn (array $e) => "{$e[0]} {$e[1]}")->sort()->values()->all(), $inCollection);
        $send = $requests->firstWhere('name', 'Send a message');
        $this->assertSame(['to' => '+971501234567', 'type' => 'text', 'text' => 'Your order is on its way'], json_decode($send['request']['body']['raw'], true));
        $this->assertSame('formdata', $requests->firstWhere('name', 'Upload a file')['request']['body']['mode']);
        $this->assertSame(202, $send['response'][0]['code']);

        // OpenAPI: the same paths and methods.
        $openapi = $this->getJson('/api/public/v1/openapi.json')->assertOk()->json();
        $this->assertSame('3.0.3', $openapi['openapi']);
        $inOpenapi = collect($openapi['paths'])->flatMap(fn (array $methods, string $path) => collect(array_keys($methods))->map(fn (string $m) => strtoupper($m).' '.$path))->sort()->values()->all();
        $this->assertSame(collect($endpoints)->map(fn (array $e) => "{$e[0]} {$e[1]}")->sort()->values()->all(), $inOpenapi);
    }
}
