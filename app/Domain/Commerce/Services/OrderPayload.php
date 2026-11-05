<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * A WooCommerce order as the plugin sends it (or as a REST webhook sends it — the same shape, with
 * refunds and meta data instead of the plugin's total_refunded, xl and attribution), reduced to
 * what Sales needs. Money stays in the store's currency.
 */
final class OrderPayload
{
    /**
     * @param  list<array{product_id: ?string, sku: ?string, name: string, quantity: int, total: float}>  $items
     * @param  array<string, string>|null  $storeSource
     */
    public function __construct(
        public readonly string $id,
        public readonly string $number,
        public readonly string $status,
        public readonly string $currency,
        public readonly float $total,
        public readonly float $refunded,
        public readonly Carbon $placedAt,
        public readonly ?Carbon $paidAt,
        public readonly ?Carbon $completedAt,
        public readonly ?Carbon $modifiedAt,
        public readonly ?string $phone,
        public readonly ?string $country,
        public readonly ?string $name,
        public readonly array $items,
        public readonly ?string $token,
        public readonly ?array $storeSource,
    ) {}

    /** @param array<string, mixed> $p */
    public static function parse(array $p): ?self
    {
        $id = trim((string) ($p['id'] ?? ''));
        if ($id === '' || strlen($id) > 64) {
            return null;
        }
        $billing = is_array($p['billing'] ?? null) ? $p['billing'] : [];
        $shipping = is_array($p['shipping'] ?? null) ? $p['shipping'] : [];
        $meta = [];
        foreach ((array) ($p['meta_data'] ?? []) as $m) {
            if (is_array($m) && isset($m['key']) && is_scalar($m['value'] ?? null)) {
                $meta[(string) $m['key']] = (string) $m['value'];
            }
        }

        $refunded = isset($p['total_refunded']) ? abs((float) $p['total_refunded']) : 0.0;
        if (! isset($p['total_refunded'])) {
            foreach ((array) ($p['refunds'] ?? []) as $r) {
                $refunded += is_array($r) ? abs((float) ($r['total'] ?? 0)) : 0;
            }
        }

        $items = [];
        foreach ((array) ($p['line_items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $product = $item['variation_id'] ?? null ?: ($item['product_id'] ?? null);
            $items[] = [
                'product_id' => $product !== null && $product !== '' && $product !== 0 ? mb_substr((string) $product, 0, 64) : null,
                'sku' => ($item['sku'] ?? '') !== '' ? mb_substr((string) $item['sku'], 0, 100) : null,
                'name' => mb_substr(trim(strip_tags((string) ($item['name'] ?? ''))) ?: 'Item', 0, 255),
                'quantity' => max(0, (int) ($item['quantity'] ?? 1)),
                'total' => round((float) ($item['total'] ?? 0), 2),
            ];
        }

        $token = (string) ($p['xl'] ?? $meta['_xe_link_token'] ?? $meta['xe_link_token'] ?? '');
        $attribution = is_array($p['attribution'] ?? null) ? $p['attribution'] : [
            'source_type' => $meta['_wc_order_attribution_source_type'] ?? null,
            'utm_source' => $meta['_wc_order_attribution_utm_source'] ?? null,
            'utm_medium' => $meta['_wc_order_attribution_utm_medium'] ?? null,
            'utm_campaign' => $meta['_wc_order_attribution_utm_campaign'] ?? null,
            'referrer' => $meta['_wc_order_attribution_referrer'] ?? null,
        ];
        $storeSource = array_filter(array_map(fn ($v) => is_scalar($v) ? mb_substr(trim((string) $v), 0, 200) : '', array_intersect_key($attribution, array_flip(['source_type', 'utm_source', 'utm_medium', 'utm_campaign', 'referrer']))), fn (string $v) => $v !== '');
        $first = trim((string) ($billing['first_name'] ?? $shipping['first_name'] ?? ''));
        $name = trim($first.' '.($billing['last_name'] ?? $shipping['last_name'] ?? ''));

        return new self(
            id: $id,
            number: mb_substr((string) ($p['number'] ?? $id), 0, 64),
            status: mb_substr(strtolower((string) ($p['status'] ?? 'pending')), 0, 24),
            currency: strtoupper(mb_substr((string) ($p['currency'] ?? 'AED'), 0, 3)) ?: 'AED',
            total: round((float) ($p['total'] ?? 0), 2),
            refunded: round($refunded, 2),
            placedAt: self::date($p, 'date_created') ?? Carbon::now(),
            paidAt: self::date($p, 'date_paid'),
            completedAt: self::date($p, 'date_completed'),
            modifiedAt: self::date($p, 'date_modified'),
            phone: (trim((string) ($billing['phone'] ?? '')) ?: trim((string) ($shipping['phone'] ?? ''))) ?: null,
            country: ((string) ($billing['country'] ?? $shipping['country'] ?? '')) ?: null,
            name: $name !== '' ? mb_substr($name, 0, 120) : null,
            items: $items,
            token: $token !== '' ? mb_substr($token, 0, 64) : null,
            storeSource: $storeSource !== [] ? $storeSource : null,
        );
    }

    /**
     * WooCommerce sends date_x_gmt (UTC, no zone) and date_x (store time); the plugin sends ISO 8601.
     *
     * @param  array<string, mixed>  $p
     */
    private static function date(array $p, string $key): ?Carbon
    {
        $value = $p[$key.'_gmt'] ?? null;
        $utc = $value !== null;
        $value ??= $p[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return $utc && ! preg_match('/(Z|[+-]\d\d:?\d\d)$/', $value) ? Carbon::parse($value, 'UTC') : Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
