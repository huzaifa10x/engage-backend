<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers;

use App\Domain\Developer\Services\UrlGuard;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Secrets\SecretStore;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Connects a WooCommerce store with a REST API key the store owner creates
 * (WooCommerce → Settings → Advanced → REST API, permission Read/Write).
 *
 * With the key we create two webhooks in the store (order created, order updated) that point at
 * this integration's own address and are signed with a secret we generate, so the owner has
 * nothing to configure by hand. The store address is typed by a customer and called by our
 * servers, so it passes the same public-address check as customer webhook URLs.
 *
 * Runs inside the workspace (TenantContext) of the person connecting.
 */
class WooCommerceConnector
{
    private const TOPICS = ['order.created' => 'order placed', 'order.updated' => 'order updated'];

    public function __construct(private readonly UrlGuard $guard, private readonly SecretStore $secrets, private readonly TenantContext $context) {}

    public function deliveryUrl(string $publicId): string
    {
        return rtrim((string) config('app.url'), '/')."/api/integrations/woocommerce/{$publicId}/webhook";
    }

    public function connect(string $storeUrl, string $consumerKey, string $consumerSecret, ?string $membershipId): Integration
    {
        $base = rtrim(trim($storeUrl), '/');
        if (! preg_match('#^https?://#i', $base)) {
            $base = 'https://'.$base;
        }
        $this->guard->assertPublic($base, 'store_url');
        $externalId = strtolower((string) preg_replace('#^https?://#i', '', $base));

        if ($this->context->bypass(fn () => Integration::query()->where('provider', Integration::WOOCOMMERCE)->where('external_id', $externalId)->exists())) {
            throw ValidationException::withMessages(['store_url' => 'This store is already connected. Disconnect it first to connect it again.']);
        }

        $publicId = Str::lower(Str::random(32));
        $webhookSecret = Str::random(48);
        $webhookIds = [];
        foreach (self::TOPICS as $topic => $label) {
            $response = $this->client($consumerKey, $consumerSecret)->post($base.'/wp-json/wc/v3/webhooks', [
                'name' => '10X Engage – '.$label, 'topic' => $topic, 'delivery_url' => $this->deliveryUrl($publicId), 'secret' => $webhookSecret, 'status' => 'active',
            ]);
            if (! $response->successful() || ! is_numeric($response->json('id'))) {
                $this->remove($base, $consumerKey, $consumerSecret, $webhookIds);
                throw ValidationException::withMessages(match (true) {
                    in_array($response->status(), [401, 403], true) => ['consumer_key' => 'The store did not accept these keys. Create a REST API key with Read/Write permission and paste both parts.'],
                    $response->status() === 404 => ['store_url' => 'WooCommerce was not found at this address. Use the address of the shop itself, and check that pretty permalinks are on (Settings → Permalinks).'],
                    default => ['store_url' => 'The store answered with an error (status '.$response->status().'). Check the address and try again.'],
                });
            }
            $webhookIds[] = (int) $response->json('id');
        }

        $name = $externalId;
        try {
            $name = (string) ($this->client($consumerKey, $consumerSecret)->get($base.'/wp-json/')->json('name') ?: $externalId);
        } catch (Throwable) {
            // The shop's name is a nicety; its address will do.
        }

        return Integration::query()->create([
            'provider' => Integration::WOOCOMMERCE, 'public_id' => $publicId, 'name' => mb_substr(html_entity_decode($name), 0, 120), 'external_id' => mb_substr($externalId, 0, 255), 'status' => 'active',
            'secret_id' => $this->secrets->put('integration.woocommerce', (string) json_encode(['key' => $consumerKey, 'secret' => $consumerSecret]), $this->context->id()),
            'webhook_secret' => $webhookSecret,
            'settings' => ['base_url' => $base, 'webhook_ids' => $webhookIds, 'tag' => 'woocommerce'],
            'connected_by_membership_id' => $membershipId,
        ]);
    }

    /** Remove our webhooks from the store and forget its keys. Best effort: the store may be gone. */
    public function disconnect(Integration $integration): void
    {
        if ($integration->secret_id === null) {
            return;
        }
        try {
            $keys = (array) json_decode($this->secrets->get($integration->secret_id), true);
            $base = (string) $integration->setting('base_url');
            $this->guard->assertPublic($base, 'store_url');
            $this->remove($base, (string) ($keys['key'] ?? ''), (string) ($keys['secret'] ?? ''), (array) $integration->setting('webhook_ids', []));
        } catch (Throwable $e) {
            Log::info('WooCommerce webhooks could not be removed on disconnect', ['integration' => $integration->id, 'error' => $e->getMessage()]);
        }
        $this->secrets->destroy($integration->secret_id);
    }

    public function signed(Integration $integration, string $body, ?string $header): bool
    {
        $secret = (string) $integration->webhook_secret;

        return $secret !== '' && is_string($header) && $header !== '' && hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), $header);
    }

    /** @param array<int, mixed> $ids */
    private function remove(string $base, string $key, string $secret, array $ids): void
    {
        foreach ($ids as $id) {
            try {
                $this->client($key, $secret)->delete($base.'/wp-json/wc/v3/webhooks/'.(int) $id, ['force' => true]);
            } catch (Throwable) {
                // carry on with the rest
            }
        }
    }

    private function client(string $key, string $secret): PendingRequest
    {
        return Http::withBasicAuth($key, $secret)->acceptJson()->asJson()->timeout(20)->withOptions(['allow_redirects' => false]);
    }
}
