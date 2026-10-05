<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Application\Billing\StripeBilling;
use App\Http\Controllers\Controller;
use App\Infrastructure\Stripe\StripeClient;
use App\Infrastructure\Stripe\StripeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/webhooks/stripe — Stripe tells us about payments, renewals, cancellations and
 * refunds. The signature is verified on the raw body; each event is processed once (Stripe
 * retries), and a failure answers 500 so Stripe sends it again.
 */
final class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeClient $stripe, StripeBilling $billing): JsonResponse
    {
        try {
            $event = $stripe->verifyWebhook($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (StripeException $e) {
            return response()->json(['error' => $e->getMessage()], $e->httpStatus === 503 ? 503 : 400);
        }

        $id = (string) $event['id'];
        if (DB::table('stripe_events')->where('id', $id)->exists()) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        try {
            $billing->handleEvent($event);
        } catch (Throwable $e) {
            Log::error('Stripe webhook failed', ['event' => $id, 'type' => $event['type'], 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Processing failed; please retry.'], 500);
        }

        DB::table('stripe_events')->insertOrIgnore(['id' => $id, 'type' => mb_substr((string) $event['type'], 0, 64), 'processed_at' => now()]);

        return response()->json(['received' => true]);
    }
}
