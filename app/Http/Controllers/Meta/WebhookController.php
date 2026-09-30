<?php

declare(strict_types=1);

namespace App\Http\Controllers\Meta;

use App\Domain\Webhooks\WebhookInbox;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Meta webhook endpoint (configure as the app callback URL: /api/webhooks/meta).
 *   GET  — verification handshake (hub.mode / hub.verify_token / hub.challenge)
 *   POST — X-Hub-Signature-256 over the RAW body, store, 200. Nothing is processed inline.
 */
final class WebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $expected = (string) config('engage.meta.webhook_verify_token');

        // PHP turns "hub.mode" into "hub_mode" in the query array.
        if ($expected !== ''
            && $request->query('hub_mode') === 'subscribe'
            && hash_equals($expected, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request, WebhookInbox $inbox): Response
    {
        $raw = $request->getContent();

        if (strlen($raw) > (int) config('engage.meta.webhook_max_body_kb', 8192) * 1024) {
            Log::error('Meta webhook body exceeds the configured maximum.', ['bytes' => strlen($raw)]);

            return response('Payload too large', 413);
        }

        if (! $this->validSignature($raw, (string) $request->header('X-Hub-Signature-256'))) {
            // Throttled logging: an attacker must not be able to flood the logs.
            RateLimiter::attempt('meta-webhook-bad-signature', 20, fn () => Log::warning('Rejected Meta webhook with an invalid signature.', ['ip' => $request->ip()]), 60);

            return response('Invalid signature', 401);
        }

        $body = json_decode($raw, true);
        if (! is_array($body)) {
            return response('OK', 200); // signed but not JSON: acknowledge, nothing to do
        }

        $inbox->accept($raw, $body);

        return response('EVENT_RECEIVED', 200);
    }

    private function validSignature(string $raw, string $header): bool
    {
        $secret = (string) config('engage.meta.app_secret');

        if ($secret === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $raw, $secret), substr($header, 7));
    }
}
