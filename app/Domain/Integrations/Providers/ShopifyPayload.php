<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers;

use App\Domain\Integrations\Services\StoreEvent;

/** Turns Shopify's order and checkout webhooks into StoreEvents. */
final class ShopifyPayload
{
    private const ORDER_TOPICS = ['orders/create' => 'order_created', 'orders/fulfilled' => 'order_fulfilled', 'orders/cancelled' => 'order_cancelled'];

    /** @param array<string, mixed> $p */
    public static function event(string $topic, array $p, string $storeName): ?StoreEvent
    {
        if (isset(self::ORDER_TOPICS[$topic])) {
            $token = (string) ($p['checkout_token'] ?? '');

            return new StoreEvent(
                event: self::ORDER_TOPICS[$topic],
                externalId: (string) ($p['id'] ?? ''),
                phone: self::phone($p),
                country: self::country($p),
                name: self::name($p),
                email: self::text($p['email'] ?? $p['customer']['email'] ?? null),
                acceptsMarketing: self::acceptsMarketing($p),
                data: self::data($p, $storeName),
                // The customer finished the order, so a reminder waiting for that checkout must not go out.
                cancels: $topic === 'orders/create' && $token !== '' ? [$token] : [],
            );
        }

        if ($topic === 'checkouts/create' || $topic === 'checkouts/update') {
            $token = (string) ($p['token'] ?? $p['id'] ?? '');
            if ($token === '') {
                return null;
            }
            if (! empty($p['completed_at'])) {
                return new StoreEvent(event: null, cancels: [$token]);
            }

            return new StoreEvent(
                event: 'checkout_abandoned',
                externalId: $token,
                phone: self::phone($p),
                country: self::country($p),
                name: self::name($p),
                email: self::text($p['email'] ?? $p['customer']['email'] ?? null),
                acceptsMarketing: self::acceptsMarketing($p),
                data: self::data($p, $storeName),
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, string>
     */
    private static function data(array $p, string $storeName): array
    {
        $first = trim((string) ($p['customer']['first_name'] ?? $p['shipping_address']['first_name'] ?? $p['billing_address']['first_name'] ?? ''));
        $fulfillment = is_array($p['fulfillments'] ?? null) ? (array) (array_values($p['fulfillments'])[count($p['fulfillments']) - 1] ?? []) : [];
        $items = [];
        foreach ((array) ($p['line_items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = ['name' => trim((string) ($item['title'] ?? $item['name'] ?? '')), 'quantity' => (int) ($item['quantity'] ?? 1)];
            }
        }
        $status = (string) ($p['order_status_url'] ?? '');
        $checkout = (string) ($p['abandoned_checkout_url'] ?? '');

        return array_filter([
            'customer_first_name' => $first,
            'customer_name' => (string) self::name($p),
            'order_number' => ltrim((string) ($p['name'] ?? $p['order_number'] ?? ''), '#'),
            'order_total' => self::money($p['total_price'] ?? null, (string) ($p['currency'] ?? $p['presentment_currency'] ?? '')),
            'items' => self::items($items),
            'first_item' => $items[0]['name'] ?? '',
            'store_name' => $storeName,
            'tracking_number' => (string) ($fulfillment['tracking_number'] ?? ''),
            'tracking_company' => (string) ($fulfillment['tracking_company'] ?? ''),
            'tracking_url' => (string) ($fulfillment['tracking_url'] ?? ''),
            'order_status_url' => $status,
            'order_status_path' => self::path($status),
            'checkout_url' => $checkout,
            'checkout_path' => self::path($checkout),
        ], fn (string $v) => $v !== '');
    }

    /** @param array<string, mixed> $p */
    private static function phone(array $p): ?string
    {
        foreach ([$p['phone'] ?? null, $p['customer']['phone'] ?? null, $p['shipping_address']['phone'] ?? null, $p['billing_address']['phone'] ?? null, $p['sms_marketing_phone'] ?? null] as $phone) {
            if (is_string($phone) && trim($phone) !== '') {
                return trim($phone);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $p */
    private static function country(array $p): ?string
    {
        return self::text($p['shipping_address']['country_code'] ?? $p['billing_address']['country_code'] ?? $p['customer']['default_address']['country_code'] ?? null);
    }

    /** @param array<string, mixed> $p */
    private static function name(array $p): ?string
    {
        $name = trim(($p['customer']['first_name'] ?? '').' '.($p['customer']['last_name'] ?? ''));

        return $name !== '' ? $name : self::text($p['shipping_address']['name'] ?? $p['billing_address']['name'] ?? null);
    }

    /** @param array<string, mixed> $p */
    private static function acceptsMarketing(array $p): bool
    {
        $state = strtolower((string) ($p['customer']['sms_marketing_consent']['state'] ?? ''));

        return $state === 'subscribed' || ($p['buyer_accepts_sms_marketing'] ?? false) === true;
    }

    public static function money(mixed $amount, string $currency): string
    {
        return is_numeric($amount) ? trim($currency.' '.number_format((float) $amount, 2)) : '';
    }

    /** @param list<array{name: string, quantity: int}> $items */
    public static function items(array $items): string
    {
        $parts = array_map(fn (array $i) => ($i['quantity'] > 1 ? $i['quantity'].' x ' : '').$i['name'], array_slice($items, 0, 5));
        $more = count($items) - count($parts);

        return mb_substr(implode(', ', $parts).($more > 0 ? " and {$more} more" : ''), 0, 500);
    }

    /** "https://shop.com/orders/abc?key=1" → "orders/abc?key=1", for a button whose address ends in a variable. */
    public static function path(string $url): string
    {
        $parts = $url !== '' ? parse_url($url) : false;
        if (! is_array($parts)) {
            return '';
        }

        return ltrim(($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : ''), '/');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
