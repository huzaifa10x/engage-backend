<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Domain\Integrations\Jobs\HandleStoreWebhook;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Integrations\Providers\ShopifyConnector;
use App\Domain\Integrations\Providers\WooCommerceConnector;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Everything stores send us. None of it is signed in by a person, so each request proves itself:
 * Shopify with the app secret, a WooCommerce store with the secret we gave its webhooks.
 * Verified notifications are queued and answered at once.
 */
final class StoreWebhookController extends Controller
{
    private const COMPLIANCE = ['customers/data_request', 'customers/redact', 'shop/redact'];

    public function __construct(private readonly TenantContext $context) {}

    // ── Shopify ────────────────────────────────────────────────────────────────────────────

    /** Where Shopify sends the merchant when the app is opened or installed from Shopify's side: on to our portal. */
    public function shopifyApp(Request $request, ShopifyConnector $shopify): RedirectResponse
    {
        $shop = $shopify->shop((string) $request->query('shop', ''));

        return redirect()->away(config('engage.frontend_url').'/integrations'.($shop !== null ? '?shopify='.urlencode($shop) : ''));
    }

    /** Shopify returns the merchant here after they approve the app. */
    public function shopifyCallback(Request $request, ShopifyConnector $shopify): RedirectResponse
    {
        $back = config('engage.frontend_url').'/integrations';
        try {
            $integration = $shopify->complete($request->query());

            return redirect()->away($back.'?connected='.$integration->id);
        } catch (RuntimeException $e) {
            return redirect()->away($back.'?error='.urlencode($e->getMessage()));
        } catch (Throwable $e) {
            Log::error('Shopify connection failed', ['error' => $e->getMessage()]);

            return redirect()->away($back.'?error='.urlencode('Shopify could not be connected. Please try again.'));
        }
    }

    public function shopify(Request $request, ShopifyConnector $shopify): Response
    {
        $body = $request->getContent();
        if (! $shopify->signedWebhook($body, $request->header('X-Shopify-Hmac-Sha256'))) {
            return response('Invalid signature', 401);
        }
        $topic = strtolower((string) $request->header('X-Shopify-Topic'));
        $shop = $shopify->shop((string) $request->header('X-Shopify-Shop-Domain', ''));
        $payload = (array) json_decode($body, true);
        /** @var ?Integration $integration */
        $integration = $shop === null ? null : $this->context->bypass(fn () => Integration::query()->where('provider', Integration::SHOPIFY)->where('external_id', $shop)->first());

        // A store we do not know (disconnected here but the app is still installed there): nothing to do, and nothing to retry.
        if ($integration === null) {
            return response('', 200);
        }

        if ($topic === 'app/uninstalled' || $topic === 'shop/redact') {
            // The merchant removed the app (or Shopify asks us to erase the shop, 48 hours later): forget the store.
            $this->context->bypass(fn () => $integration->delete());
        } elseif ($topic === 'customers/redact') {
            $this->eraseCustomer($integration, $payload);
        } elseif (! in_array($topic, self::COMPLIANCE, true)) {
            HandleStoreWebhook::dispatch($integration->id, $topic, $payload);
        }
        // customers/data_request: what we hold about a store's customer is the contact in the workspace,
        // which the workspace can export from Contacts; the request is acknowledged here.

        return response('', 200);
    }

    /**
     * Shopify's "erase this customer" request: remove what our integration log holds about them.
     * (The contact itself belongs to the workspace, which deletes it from Contacts or Compliance.)
     *
     * @param  array<string, mixed>  $payload
     */
    private function eraseCustomer(Integration $integration, array $payload): void
    {
        $orders = array_map('strval', (array) ($payload['orders_to_redact'] ?? []));
        $phone = (string) ($payload['customer']['phone'] ?? '');
        if ($orders === [] && $phone === '') {
            return;
        }
        $this->context->bypass(fn () => IntegrationEvent::query()->where('integration_id', $integration->id)
            ->where(fn ($q) => $q->whereIn('external_id', $orders)->when($phone !== '', fn ($q) => $q->orWhere('phone', $phone)))
            ->update(['data' => null, 'phone' => null]));
    }

    // ── WooCommerce ────────────────────────────────────────────────────────────────────────

    public function woocommerce(Request $request, string $publicId, WooCommerceConnector $woo): Response
    {
        $body = $request->getContent();
        // WooCommerce checks the address with an unsigned "webhook_id=123" request when the webhook is created.
        if ($request->header('X-WC-Webhook-Signature') === null && preg_match('/^webhook_id=\d+$/', trim($body)) === 1) {
            return response('', 200);
        }

        /** @var ?Integration $integration */
        $integration = $this->context->bypass(fn () => Integration::query()->where('provider', Integration::WOOCOMMERCE)->where('public_id', $publicId)->first());
        if ($integration === null) {
            return response('', 410); // disconnected: WooCommerce switches the webhook off after repeated failures
        }
        if (! $woo->signed($integration, $body, $request->header('X-WC-Webhook-Signature'))) {
            return response('Invalid signature', 401);
        }

        $topic = strtolower((string) $request->header('X-WC-Webhook-Topic'));
        $payload = json_decode($body, true);
        if (is_array($payload) && str_starts_with($topic, 'order.')) {
            HandleStoreWebhook::dispatch($integration->id, $topic, $payload);
        }

        return response('', 200);
    }
}
