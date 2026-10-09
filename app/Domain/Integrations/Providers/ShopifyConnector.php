<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Connects a Shopify store through Shopify's own install screen (OAuth authorization code grant).
 *
 *   1. installUrl()  the portal sends the merchant to Shopify to approve the app
 *   2. complete()    Shopify sends them back with a code; we check the request really is from
 *                    Shopify, swap the code for a token, and ask Shopify to notify us about
 *                    orders and checkouts for that store
 *
 * The return trip comes from Shopify, not from our portal, so it cannot rely on the login
 * session. Instead step 1 leaves a single-use note (who started it, for which workspace and
 * store) and step 2 only proceeds if it finds that note.
 *
 * The token is used once, to register the webhooks, and is not kept: nothing afterwards calls
 * Shopify, and a credential that is not stored cannot leak.
 */
class ShopifyConnector
{
    public const TOPICS = ['ORDERS_CREATE', 'ORDERS_FULFILLED', 'ORDERS_CANCELLED', 'CHECKOUTS_CREATE', 'CHECKOUTS_UPDATE', 'APP_UNINSTALLED'];

    private const STATE_MINUTES = 15;

    public function __construct(private readonly TenantContext $context) {}

    public function configured(): bool
    {
        return (string) config('engage.shopify.client_id') !== '' && (string) config('engage.shopify.client_secret') !== '';
    }

    /** "My-Store", "my-store.myshopify.com" or "https://my-store.myshopify.com/admin" → "my-store.myshopify.com". */
    public function shop(string $input): ?string
    {
        $shop = strtolower(trim($input));
        $shop = (string) preg_replace('#^https?://#', '', $shop);
        $shop = explode('/', $shop)[0];
        if ($shop !== '' && ! str_contains($shop, '.')) {
            $shop .= '.myshopify.com';
        }

        return preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $shop) === 1 ? $shop : null;
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/integrations/shopify/webhook';
    }

    public function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/integrations/shopify/callback';
    }

    public function installUrl(string $shop, string $tenantId, ?string $membershipId): string
    {
        $state = Str::random(48);
        Cache::put('shopify-install:'.$state, ['tenant_id' => $tenantId, 'membership_id' => $membershipId, 'shop' => $shop], now()->addMinutes(self::STATE_MINUTES));

        return "https://{$shop}/admin/oauth/authorize?".http_build_query([
            'client_id' => (string) config('engage.shopify.client_id'),
            'scope' => (string) config('engage.shopify.scopes'),
            'redirect_uri' => $this->callbackUrl(),
            'state' => $state,
        ]);
    }

    /**
     * Shopify signs the parameters it sends back: every parameter except "hmac", sorted by name,
     * joined as a query string, HMAC-SHA256 with the app's secret, as hex.
     *
     * @param  array<string, mixed>  $query
     */
    public function signedByShopify(array $query): bool
    {
        $hmac = (string) ($query['hmac'] ?? '');
        unset($query['hmac'], $query['signature']);
        ksort($query);
        $message = implode('&', array_map(fn (string $k, mixed $v) => $k.'='.(is_array($v) ? implode(',', $v) : (string) $v), array_keys($query), $query));

        return $hmac !== '' && hash_equals(hash_hmac('sha256', $message, (string) config('engage.shopify.client_secret')), $hmac);
    }

    /** Webhooks carry the HMAC-SHA256 of the raw body, base64 encoded, in X-Shopify-Hmac-Sha256. */
    public function signedWebhook(string $body, ?string $header): bool
    {
        $secret = (string) config('engage.shopify.client_secret');

        return $secret !== '' && is_string($header) && $header !== '' && hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), $header);
    }

    /**
     * @param  array<string, mixed>  $query  what Shopify sent to the callback
     *
     * @throws RuntimeException with a message that is safe to show the user
     */
    public function complete(array $query): Integration
    {
        $shop = $this->shop((string) ($query['shop'] ?? ''));
        if ($shop === null || ! $this->signedByShopify($query)) {
            throw new RuntimeException('The request did not come from Shopify. Start the connection again from Integrations.');
        }
        /** @var ?array{tenant_id: string, membership_id: ?string, shop: string} $note */
        $note = Cache::pull('shopify-install:'.(string) ($query['state'] ?? ''));
        if (! is_array($note) || $note['shop'] !== $shop) {
            throw new RuntimeException('This connection attempt has expired. Start again from Integrations.');
        }

        $token = Http::asForm()->acceptJson()->timeout(20)->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => (string) config('engage.shopify.client_id'),
            'client_secret' => (string) config('engage.shopify.client_secret'),
            'code' => (string) ($query['code'] ?? ''),
            'expiring' => 1,
        ]);
        $accessToken = (string) $token->json('access_token');
        if (! $token->successful() || $accessToken === '') {
            throw new RuntimeException('Shopify did not accept the connection. Please try again.');
        }

        $name = (string) ($this->graphql($shop, $accessToken, '{ shop { name } }')['data']['shop']['name'] ?? '') ?: $shop;
        $this->subscribe($shop, $accessToken);

        $tenant = $this->context->bypass(fn () => Tenant::query()->find($note['tenant_id']));
        if ($tenant === null) {
            throw new RuntimeException('The workspace no longer exists.');
        }
        $elsewhere = $this->context->bypass(fn () => Integration::query()->where('provider', Integration::SHOPIFY)->where('external_id', $shop)->where('tenant_id', '!=', $tenant->id)->exists());
        if ($elsewhere) {
            throw new RuntimeException('This store is already connected to another 10X Engage workspace. Disconnect it there first.');
        }

        return $this->context->run($tenant, function () use ($shop, $name, $note, $token): Integration {
            $integration = Integration::query()->where('provider', Integration::SHOPIFY)->where('external_id', $shop)->first();
            $granted = array_values(array_filter(explode(',', (string) $token->json('scope'))));
            if ($integration !== null) {
                $integration->forceFill(['name' => $name, 'status' => 'active', 'last_error' => null, 'settings' => ['scopes' => $granted] + (array) $integration->settings])->save();

                return $integration;
            }

            return Integration::query()->create([
                'provider' => Integration::SHOPIFY, 'public_id' => Str::lower(Str::random(32)), 'name' => mb_substr($name, 0, 120), 'external_id' => $shop, 'status' => 'active',
                'settings' => ['scopes' => $granted, 'tag' => 'shopify'], 'connected_by_membership_id' => $note['membership_id'],
            ]);
        });
    }

    /** Ask Shopify to send this store's order and checkout events to us. Safe to repeat. */
    private function subscribe(string $shop, string $accessToken): void
    {
        $mutation = 'mutation($topic: WebhookSubscriptionTopic!, $sub: WebhookSubscriptionInput!) { webhookSubscriptionCreate(topic: $topic, webhookSubscription: $sub) { webhookSubscription { id } userErrors { field message } } }';
        foreach (self::TOPICS as $topic) {
            $result = $this->graphql($shop, $accessToken, $mutation, ['topic' => $topic, 'sub' => ['uri' => $this->webhookUrl()]]);
            $errors = array_merge((array) ($result['errors'] ?? []), (array) ($result['data']['webhookSubscriptionCreate']['userErrors'] ?? []));
            foreach ($errors as $error) {
                $message = strtolower((string) (is_array($error) ? ($error['message'] ?? '') : $error));
                if (str_contains($message, 'already') || str_contains($message, 'taken')) {
                    continue; // subscribed on an earlier connection
                }
                // Checkouts need extra approval on some stores: orders must still work without them.
                if (str_starts_with($topic, 'CHECKOUTS_')) {
                    continue;
                }
                throw new RuntimeException('Shopify refused to send order updates: '.mb_substr((string) (is_array($error) ? ($error['message'] ?? 'unknown reason') : $error), 0, 160));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphql(string $shop, string $accessToken, string $query, array $variables = []): array
    {
        $version = (string) config('engage.shopify.api_version');
        $response = Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])->acceptJson()->timeout(20)
            ->post("https://{$shop}/admin/api/{$version}/graphql.json", array_filter(['query' => $query, 'variables' => $variables ?: null]));
        if (! $response->successful()) {
            throw new RuntimeException('Shopify could not be reached (status '.$response->status().'). Please try again.');
        }

        return (array) $response->json();
    }
}
