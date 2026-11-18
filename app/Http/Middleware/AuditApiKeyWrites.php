<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Audit\AuditLogger;
use App\Domain\Developer\ApiCaller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batch 6, item 6: every change made with an API key is in the Audit log, with the key's name,
 * what was asked (method and endpoint) and the answer's status. Reads are not logged.
 */
final class AuditApiKeyWrites
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe() && ApiCaller::current() !== null && $response->getStatusCode() < 500) {
            $this->audit->record('api.request', meta: [
                'method' => $request->method(),
                'endpoint' => '/'.ltrim(str_replace('api/public/v1', '', (string) $request->route()?->uri()), '/'),
                'status' => $response->getStatusCode(),
            ]);
        }

        return $response;
    }
}
