<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\PublicV1;

use App\Domain\Developer\ApiSpec;
use App\Domain\Plans\FeatureKey;
use App\Domain\Plans\Models\Plan;
use App\Domain\Plans\Models\PlanVersionFeature;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The published description of the public API, in three formats generated from ApiSpec:
 * the documentation's data, the official Postman collection, and OpenAPI 3. No key needed.
 */
final class ApiDocsController extends Controller
{
    public function spec(): JsonResponse
    {
        return response()->json(['data' => ApiSpec::document() + ['rate_limits' => $this->rateLimits()]])->header('Cache-Control', 'public, max-age=300');
    }

    /** Postman Collection v2.1. Import the URL or the file; set the "api_key" variable and send. */
    public function postman(): JsonResponse
    {
        $doc = ApiSpec::document();
        $folders = [];
        foreach ($doc['groups'] as $group) {
            $items = [];
            foreach ($group['endpoints'] as $e) {
                $examples = array_merge(isset($e['request']['example']) ? [['title' => $e['summary'], 'example' => $e['request']['example']]] : [], $e['request']['more'] ?? []);
                foreach ($examples === [] ? [['title' => $e['summary'], 'example' => null]] : $examples as $i => $variant) {
                    $items[] = $this->postmanRequest($e, $i === 0 ? $e['summary'] : "{$e['summary']}: {$variant['title']}", $variant['example']);
                }
            }
            $folders[] = ['name' => $group['name'], 'description' => $group['description'], 'item' => $items];
        }

        return response()->json([
            'info' => [
                'name' => $doc['title'].' ('.$doc['version'].')',
                // The same id for every download of this version, so re-importing updates the collection instead of duplicating it.
                '_postman_id' => preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', md5('10x-engage-api-'.$doc['version'])),
                'description' => $doc['introduction']."\n\nSet the collection variable `api_key` to a key from Developer → API keys. Requests that need an id use variables such as `contact_id`: paste a real id from an earlier response.\n\nThis collection is generated from the live API description, so it always matches the API it was downloaded from.",
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{api_key}}', 'type' => 'string']]],
            'variable' => [
                ['key' => 'base_url', 'value' => $doc['base_url']],
                ['key' => 'api_key', 'value' => 'eng_live_YOUR_KEY'],
                ['key' => 'contact_id', 'value' => ''],
                ['key' => 'conversation_id', 'value' => ''],
                ['key' => 'message_id', 'value' => ''],
            ],
            'item' => $folders,
        ])->header('Content-Disposition', 'attachment; filename="10x-engage-api.postman_collection.json"');
    }

    /** OpenAPI 3.0, for code generators and other API tools. */
    public function openapi(): JsonResponse
    {
        $doc = ApiSpec::document();
        $paths = [];
        foreach ($doc['groups'] as $group) {
            foreach ($group['endpoints'] as $e) {
                $parameters = [];
                foreach ($e['path_params'] ?? [] as $p) {
                    $parameters[] = ['name' => $p['name'], 'in' => 'path', 'required' => true, 'description' => $p['description'], 'schema' => ['type' => 'string', 'format' => 'uuid']];
                }
                foreach ($e['query'] ?? [] as $p) {
                    $parameters[] = ['name' => $p['name'], 'in' => 'query', 'required' => false, 'description' => $p['description'], 'schema' => ['type' => $p['type'] === 'integer' ? 'integer' : 'string']];
                }
                foreach ($e['headers'] ?? [] as $p) {
                    $parameters[] = ['name' => $p['name'], 'in' => 'header', 'required' => false, 'description' => $p['description'], 'schema' => ['type' => 'string']];
                }
                $operation = [
                    'operationId' => Str::camel(str_replace(['.', '-'], '_', $e['id'])),
                    'tags' => [$group['name']],
                    'summary' => $e['summary'],
                    'description' => $e['description'].($e['scope'] ? "\n\nPermission: `{$e['scope']}`" : ''),
                    'parameters' => $parameters,
                    'responses' => [(string) $e['response']['status'] => ['description' => 'Success', 'content' => ['application/json' => ['example' => $e['response']['example']]]]]
                        + ['401' => ['description' => 'Missing or invalid API key'], '429' => ['description' => 'Rate limit exceeded']],
                ];
                if (isset($e['body'])) {
                    $type = $e['content_type'] ?? 'application/json';
                    $operation['requestBody'] = ['required' => true, 'content' => [$type => array_filter([
                        'schema' => ['type' => 'object', 'properties' => $this->properties($e['body'])],
                        'example' => $e['request']['example'] ?? null,
                    ])]];
                }
                $paths[$e['path']][strtolower($e['method'])] = $operation;
            }
        }

        return response()->json([
            'openapi' => '3.0.3',
            'info' => ['title' => $doc['title'], 'version' => $doc['version'], 'description' => $doc['introduction']],
            'servers' => [['url' => $doc['base_url']]],
            'security' => [['apiKey' => []]],
            'components' => ['securitySchemes' => ['apiKey' => ['type' => 'http', 'scheme' => 'bearer', 'description' => $doc['authentication']['summary']]]],
            'tags' => array_map(fn (array $g) => ['name' => $g['name'], 'description' => $g['description']], $doc['groups']),
            'paths' => $paths,
        ]);
    }

    /**
     * @param  array<string, mixed>  $e
     * @param  ?array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function postmanRequest(array $e, string $name, ?array $body): array
    {
        // /contacts/{id}/opt-in → {{base_url}}/contacts/{{contact_id}}/opt-in
        $variable = match (true) {
            str_starts_with($e['path'], '/contacts/') => 'contact_id',
            str_starts_with($e['path'], '/conversations/') => 'conversation_id',
            str_starts_with($e['path'], '/messages/') => 'message_id',
            default => 'id',
        };
        $path = str_replace('{id}', '{{'.$variable.'}}', $e['path']);
        $request = [
            'method' => $e['method'],
            'header' => $e['id'] === 'messages.send'
                ? [['key' => 'Accept', 'value' => 'application/json'], ['key' => 'Idempotency-Key', 'value' => '{{$guid}}', 'description' => 'A new value each time you press Send. Use your own id to make retries safe.']]
                : [['key' => 'Accept', 'value' => 'application/json']],
            'url' => [
                'raw' => '{{base_url}}'.$path,
                'host' => ['{{base_url}}'],
                'path' => array_values(array_filter(explode('/', $path))),
                'query' => array_map(fn (array $q) => ['key' => $q['name'], 'value' => '', 'description' => $q['description'], 'disabled' => true], $e['query'] ?? []),
            ],
            'description' => $e['description'].($e['scope'] ? "\n\nPermission needed: `{$e['scope']}`" : ''),
        ];
        if (($e['content_type'] ?? '') === 'multipart/form-data') {
            $request['body'] = ['mode' => 'formdata', 'formdata' => [['key' => 'file', 'type' => 'file', 'src' => [], 'description' => 'Choose a file']]];
        } elseif ($body !== null) {
            $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $request['body'] = ['mode' => 'raw', 'raw' => (string) json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'options' => ['raw' => ['language' => 'json']]];
        }

        return [
            'name' => $name,
            'request' => $request,
            'response' => [[
                'name' => 'Example', 'originalRequest' => $request, 'status' => 'OK', 'code' => $e['response']['status'], '_postman_previewlanguage' => 'json',
                'header' => [['key' => 'Content-Type', 'value' => 'application/json']],
                'body' => (string) json_encode($e['response']['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]],
        ];
    }

    /**
     * @param  list<array{name: string, type: string, description: string}>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function properties(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $out[$f['name']] = match (true) {
                $f['type'] === 'file' => ['type' => 'string', 'format' => 'binary'],
                str_starts_with($f['type'], 'array') => ['type' => 'array', 'items' => ['type' => 'string']],
                in_array($f['type'], ['boolean', 'integer', 'object'], true) => ['type' => $f['type']],
                default => ['type' => 'string'],
            } + ['description' => $f['description']];
        }

        return $out;
    }

    /**
     * Requests per minute for each public plan that includes API access, from the live catalog.
     *
     * @return list<array{plan: string, requests_per_minute: int|null}>
     */
    private function rateLimits(): array
    {
        return Cache::remember('public:api-rate-limits', 300, function (): array {
            $rows = [];
            foreach (Plan::query()->where('is_public', true)->where('is_active', true)->orderBy('sort_order')->get() as $plan) {
                $version = $plan->activeVersion();
                if ($version === null) {
                    continue;
                }
                $features = PlanVersionFeature::query()->where('plan_version_id', $version->id)->with('feature')->get()->keyBy(fn (PlanVersionFeature $r) => $r->feature?->key);
                if (! ($features[FeatureKey::ApiAccess->value]->enabled ?? false)) {
                    continue;
                }
                $limit = $features[FeatureKey::ApiRateLimitPerMinute->value] ?? null;
                $rows[] = ['plan' => $plan->name, 'requests_per_minute' => $limit?->enabled ? $limit->limit_value : null];
            }

            return $rows;
        });
    }
}
