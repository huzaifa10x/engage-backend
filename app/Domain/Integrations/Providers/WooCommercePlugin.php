<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers;

use App\Domain\Commerce\Services\ChatLinks;
use App\Domain\Growth\GrowthAccess;
use App\Domain\Growth\Models\GrowthSite;
use App\Domain\Growth\Website\Sites;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The "10X Engage for WooCommerce" plugin (Engagement Plan v4 §V.1).
 *
 * Connecting is one screen: Integrations gives a workspace key (valid 7 days, used once); the store
 * owner pastes it into the plugin, which calls connect() with the store's address. We answer with
 * the store's ID and its own signing secret. From then on everything the plugin sends is signed
 * with that secret over "timestamp.body"; requests older than five minutes are refused (replays),
 * and duplicates are ignored by order + event + time.
 *
 * The key carries our API address, so one paste is enough on staging and production alike.
 */
final class WooCommercePlugin
{
    public const KEY_PREFIX = 'xewc_';

    public const KEY_DAYS = 7;

    public const MAX_SKEW = 300;

    public function __construct(private readonly TenantContext $context, private readonly Sites $sites) {}

    /**
     * A new workspace key. For a store already connected with REST keys, the key upgrades that
     * store to the plugin; otherwise a waiting store is created and gets its address on connect.
     *
     * @return array{integration: Integration, key: string}
     */
    public function issueKey(?Integration $existing, ?string $membershipId): array
    {
        $secret = Str::random(40);
        if ($existing === null) {
            // One waiting store at a time: a new key replaces an unused one.
            Integration::query()->where('provider', Integration::WOOCOMMERCE)->where('external_id', 'like', 'pending:%')->delete();
            $publicId = Str::lower(Str::random(32));
            $existing = Integration::query()->create([
                'provider' => Integration::WOOCOMMERCE, 'public_id' => $publicId, 'name' => 'WooCommerce store', 'external_id' => 'pending:'.$publicId,
                'status' => 'active', 'settings' => ['via' => 'plugin', 'tag' => 'woocommerce'], 'connected_by_membership_id' => $membershipId,
            ]);
        }
        $existing->forceFill(['settings' => [
            'key_digest' => hash('sha256', $secret), 'key_expires_at' => Carbon::now()->addDays(self::KEY_DAYS)->toIso8601String(),
        ] + (array) $existing->settings])->save();

        $payload = ['u' => rtrim((string) config('app.url'), '/'), 'i' => $existing->public_id, 's' => $secret];

        return ['integration' => $existing, 'key' => self::KEY_PREFIX.rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=')];
    }

    /**
     * The plugin presents the key from its settings screen.
     *
     * @param  array<string, mixed>  $meta  plugin_version, wc_version, hpos, block_checkout, currency, name
     * @return array{integration: Integration, secret: string}
     */
    public function connect(string $key, string $siteUrl, array $meta): array
    {
        $decoded = self::decode($key) ?? throw new RuntimeException('This is not a 10X Engage workspace key. Copy it again from Integrations → WooCommerce.');
        $host = Sites::normalizeDomain($siteUrl) ?? throw new RuntimeException('The store address could not be read. Check Settings → General → Site Address in WordPress.');

        /** @var ?Integration $integration */
        $integration = $this->context->bypass(fn () => Integration::query()->where('provider', Integration::WOOCOMMERCE)->where('public_id', $decoded['i'])->first());
        $digest = (string) ($integration?->setting('key_digest') ?? '');
        $expires = $integration?->setting('key_expires_at');
        if ($integration === null || $digest === '' || ! hash_equals($digest, hash('sha256', $decoded['s']))) {
            throw new RuntimeException('This key is not valid or was already used. Create a new key in Integrations → WooCommerce.');
        }
        if (is_string($expires) && Carbon::parse($expires)->isPast()) {
            throw new RuntimeException('This key has expired. Create a new key in Integrations → WooCommerce.');
        }
        $tenant = $this->context->bypass(fn () => Tenant::query()->find($integration->tenant_id)) ?? throw new RuntimeException('The workspace was not found.');

        return $this->context->run($tenant, function () use ($integration, $host, $meta, $tenant) {
            $bare = (string) preg_replace('/^www\./', '', $host);
            if (! $integration->pending() && $integration->host() !== $bare) {
                throw new RuntimeException('This key belongs to the store '.$integration->external_id.'. Create a key for this store instead.');
            }
            // Already connected to us with REST keys (this workspace): upgrade that store instead.
            /** @var ?Integration $same */
            $same = $this->context->bypass(fn () => Integration::query()->where('provider', Integration::WOOCOMMERCE)->whereIn('external_id', [$bare, 'www.'.$bare])->whereKeyNot($integration->id)->first());
            if ($same !== null && $same->tenant_id !== $integration->tenant_id) {
                throw new RuntimeException('This store is connected to another 10X Engage workspace. Disconnect it there first.');
            }
            if ($same !== null) {
                $integration->delete();
                $integration = $same;
            }

            $secret = Str::random(48);
            $settings = (array) $integration->settings;
            unset($settings['key_digest'], $settings['key_expires_at']);
            $settings['via'] = 'plugin';
            $settings['base_url'] ??= 'https://'.$host;
            $settings['plugin'] = self::pluginMeta($meta) + ['connected_at' => Carbon::now()->toIso8601String()];
            $name = trim((string) ($meta['name'] ?? ''));
            $integration->forceFill([
                'external_id' => $integration->pending() ? $host : $integration->external_id,
                'name' => $name !== '' ? mb_substr(html_entity_decode($name), 0, 120) : ($integration->pending() ? $host : $integration->name),
                'plugin_secret' => $secret, 'status' => 'active', 'settings' => $settings, 'last_error' => null,
            ])->save();
            ChatLinks::forgetStores($tenant->id);

            return ['integration' => $integration, 'secret' => $secret];
        });
    }

    /** Checks the plugin's signature and timestamp. */
    public function verify(Integration $integration, Request $request): bool
    {
        $secret = (string) $integration->plugin_secret;
        $ts = (string) $request->header('X-Engage-Timestamp', '');
        $signature = (string) $request->header('X-Engage-Signature', '');
        if ($secret === '' || ! ctype_digit($ts) || $signature === '' || abs(time() - (int) $ts) > self::MAX_SKEW) {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $ts.'.'.$request->getContent(), $secret, true)), $signature);
    }

    /**
     * The R1 website tracker the plugin prints on every page: the Tracking Setup site for this
     * domain, created with the workspace's connected numbers when there is none (Growth Hub only).
     */
    public function trackerSrc(Integration $integration): ?string
    {
        $host = $integration->host();
        $tenant = $this->context->tenantOrNull();
        if ($host === null || $tenant === null || ! GrowthAccess::full($tenant)) {
            return null;
        }
        /** @var ?GrowthSite $site */
        $site = GrowthSite::query()->whereIn('domain', [$host, 'www.'.$host])->first();
        if ($site === null && $integration->setting('tracker_auto', true) !== false) {
            $numbers = PhoneNumber::query()->where('status', PhoneNumberStatus::Connected)->pluck('id')->all();
            if ($numbers === []) {
                return null;
            }
            try {
                $site = $this->sites->create($tenant, $host, $integration->name, array_values(array_map('strval', $numbers)));
            } catch (Throwable $e) {
                Log::info('Store tracker site could not be created', ['integration' => $integration->id, 'error' => $e->getMessage()]);

                return null;
            }
        }
        if ($site === null || ! $site->enabled) {
            return null;
        }

        return Sites::collectorUrl().'/sdk/v1/e.min.js?key='.$site->site_key;
    }

    /**
     * Connection health (Engagement Plan §V.1 "Plan for failures"): the last event received and a
     * warning when the store is taking orders but no events have arrived for 24 hours, or the plugin
     * has stopped reporting.
     *
     * @return array<string, mixed>
     */
    public static function health(Integration $integration): array
    {
        $plugin = (array) $integration->setting('plugin', []);
        $at = fn (string $k) => is_string($plugin[$k] ?? null) ? Carbon::parse($plugin[$k]) : null;
        $heartbeat = $at('heartbeat_at') ?? $at('connected_at');
        $latestOrder = $at('latest_order_at');
        $last = $integration->last_event_at;
        $warnings = [];
        if ($integration->viaPlugin()) {
            if ($heartbeat !== null && $heartbeat->lt(Carbon::now()->subDay())) {
                $warnings[] = ['code' => 'plugin_silent', 'message' => 'The plugin has not reported for over 24 hours. Check that the plugin is active and WordPress cron runs.'];
            }
            if ($latestOrder !== null && ($last === null || ($latestOrder->gt($last) && $last->lt(Carbon::now()->subDay())))) {
                $warnings[] = ['code' => 'no_events', 'message' => 'The store is taking orders, but no order events have arrived for 24 hours.'];
            }
            if ((int) ($plugin['queue_pending'] ?? 0) > 50) {
                $warnings[] = ['code' => 'queue', 'message' => ((int) $plugin['queue_pending']).' order events are waiting to be sent from the store.'];
            }
        }

        return [
            'state' => $integration->pending() ? 'waiting' : ($warnings !== [] ? 'warning' : 'connected'),
            'last_event_at' => $last?->toIso8601String(),
            'heartbeat_at' => $heartbeat?->toIso8601String(),
            'latest_order_at' => $latestOrder?->toIso8601String(),
            'reconciled_at' => $plugin['reconciled_at'] ?? null,
            'plugin_version' => $plugin['plugin_version'] ?? null,
            'wc_version' => $plugin['wc_version'] ?? null,
            'hpos' => $plugin['hpos'] ?? null,
            'block_checkout' => $plugin['block_checkout'] ?? null,
            'key_expires_at' => $integration->setting('key_expires_at'),
            'warnings' => $warnings,
        ];
    }

    /** @return array{u: string, i: string, s: string}|null */
    public static function decode(string $key): ?array
    {
        $key = trim($key);
        if (! str_starts_with($key, self::KEY_PREFIX)) {
            return null;
        }
        $json = base64_decode(strtr(substr($key, strlen(self::KEY_PREFIX)), '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($data) || ! is_string($data['i'] ?? null) || ! is_string($data['s'] ?? null) || preg_match('/^[a-z0-9]{32}$/', $data['i']) !== 1) {
            return null;
        }

        return ['u' => (string) ($data['u'] ?? ''), 'i' => $data['i'], 's' => $data['s']];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function pluginMeta(array $meta): array
    {
        $out = [];
        foreach (['plugin_version' => 20, 'wc_version' => 20, 'wp_version' => 20, 'currency' => 3] as $k => $len) {
            if (is_scalar($meta[$k] ?? null) && (string) $meta[$k] !== '') {
                $out[$k] = mb_substr((string) $meta[$k], 0, $len);
            }
        }
        foreach (['hpos', 'block_checkout'] as $k) {
            if (array_key_exists($k, $meta)) {
                $out[$k] = (bool) $meta[$k];
            }
        }
        if (is_numeric($meta['queue_pending'] ?? null)) {
            $out['queue_pending'] = (int) $meta['queue_pending'];
        }
        if (is_string($meta['latest_order_at'] ?? null)) {
            try {
                $out['latest_order_at'] = Carbon::parse($meta['latest_order_at'])->utc()->toIso8601String();
            } catch (Throwable) {
                // ignored
            }
        }

        return $out;
    }
}
