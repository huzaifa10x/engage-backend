<?php

declare(strict_types=1);

namespace App\Http\Controllers\Collector;

use App\Domain\Growth\Website\Collector;
use App\Domain\Growth\Website\Sites;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * The public tracker endpoints on e.10xdigital.ae, served by the separate collector container
 * (Technical Specification v1.2 §3, §4, §8; Blueprint v1.4 hostnames):
 *
 *   GET  /sdk/v1/e.min.js?key=pk_site_…   the tracker script
 *   GET  /v1/config?site_key=pk_site_…    the site's advisory configuration (ETag, 5 min cache)
 *   POST /v1/c                            a tap (beacon, text/plain JSON, ≤ 4 KB)
 *   POST /v1/v                            "Open my site": the tracker reports the challenge back
 *
 * No cookies, no sessions, no authentication: the site key is public. Every answer is safe to
 * ignore — WhatsApp has already opened.
 */
final class CollectorController extends Controller
{
    public function __construct(private readonly Collector $collector, private readonly Sites $sites, private readonly TenantContext $context) {}

    public function sdk(): Response
    {
        $path = resource_path('growth/tracker.min.js');
        $js = is_file($path) ? (string) file_get_contents($path) : '';

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }

    public function config(Request $request): BaseResponse
    {
        $found = $this->collector->site($request->query('site_key'));
        if ($found === null) {
            return $this->cors(response()->json(['enabled' => false], 404), null);
        }
        [$site, $tenant] = $found;
        $origin = $request->headers->get('Origin');
        $allowed = Collector::allowedOrigin($site, $origin, $request->headers->get('Referer'));
        if ($allowed === null) {
            return $this->cors(response()->json(['enabled' => false], 403), null);
        }

        $this->collector->seen($site, $tenant);
        $active = Collector::active($tenant, $site) && (bool) config('engage.growth.tracker_enabled', true); // global kill switch
        $config = $this->context->run($tenant, fn () => $this->sites->config($site, $active));
        $etag = '"'.md5((string) json_encode($config)).'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return $this->cors(response('', 304, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=300']), $origin);
        }

        return $this->cors(response()->json($config, 200, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=300']), $origin);
    }

    public function click(Request $request): BaseResponse
    {
        try {
            $result = $this->collector->register(
                $request->getContent(),
                $request->headers->get('Origin'),
                $request->headers->get('Referer'),
                $request->userAgent(),
                (string) $request->ip(),
                $request->headers->get('Sec-Purpose') ?? $request->headers->get('Purpose'),
            );
        } catch (\Throwable $e) {
            Log::error('Growth Hub collector failed.', ['error' => $e->getMessage()]);

            return $this->cors(response()->json(['error' => 'unavailable'], 503, ['Retry-After' => '30']), $request->headers->get('Origin'));
        }
        $headers = isset($result[2]) ? ['Retry-After' => (string) $result[2]] : [];

        return $this->cors(response()->json($result[1], $result[0], $headers), $result[0] < 400 ? $request->headers->get('Origin') : null);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $found = is_array($data) ? $this->collector->site($data['site_key'] ?? null) : null;
        if ($found === null || ! is_string($data['challenge'] ?? null) || strlen($data['challenge']) > 64) {
            return $this->cors(response()->json(['verified' => false], 422), null);
        }
        [$site, $tenant] = $found;
        // The proof is the tracker running on the site's own domain with the admin's fresh challenge.
        if (Collector::allowedOrigin($site, $request->headers->get('Origin'), $request->headers->get('Referer')) === null) {
            return $this->cors(response()->json(['verified' => false, 'error' => 'wrong_domain'], 403), null);
        }
        $ok = $this->collector->verify($site, $tenant, $data['challenge']);

        return $this->cors(response()->json(['verified' => $ok], $ok ? 200 : 422), $request->headers->get('Origin'));
    }

    /**
     * @template T of BaseResponse
     *
     * @param  T  $response
     * @return T
     */
    private function cors(BaseResponse $response, ?string $origin): BaseResponse
    {
        if ($origin !== null && $origin !== '') {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }
        $response->headers->set('Cache-Control', $response->headers->get('Cache-Control') ?: 'no-store');

        return $response;
    }
}
