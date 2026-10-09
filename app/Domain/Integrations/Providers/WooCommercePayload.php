<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers;

use App\Domain\Integrations\Services\StoreEvent;

/**
 * Turns WooCommerce's order webhooks into StoreEvents. WooCommerce reports "order created" for
 * unpaid orders too and reports every later change as "order updated", so the order's status
 * decides what happened: placed (processing / on hold), shipped (completed) or cancelled.
 */
final class WooCommercePayload
{
    /**
     * One order update can mean more than one thing (an order created already "completed" is both placed and shipped).
     *
     * @param  array<string, mixed>  $p
     * @return list<StoreEvent>
     */
    public static function events(array $p, string $storeName): array
    {
        $id = (string) ($p['id'] ?? '');
        $status = strtolower((string) ($p['status'] ?? ''));
        if ($id === '') {
            return [];
        }

        $names = match ($status) {
            'processing', 'on-hold' => ['order_created'],
            'completed' => ['order_created', 'order_fulfilled'],
            'cancelled' => ['order_cancelled'],
            default => [],
        };

        $billing = (array) ($p['billing'] ?? []);
        $shipping = (array) ($p['shipping'] ?? []);
        $first = trim((string) ($billing['first_name'] ?? $shipping['first_name'] ?? ''));
        $name = trim($first.' '.($billing['last_name'] ?? $shipping['last_name'] ?? ''));
        $items = [];
        foreach ((array) ($p['line_items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = ['name' => trim((string) ($item['name'] ?? '')), 'quantity' => (int) ($item['quantity'] ?? 1)];
            }
        }
        $phone = trim((string) ($billing['phone'] ?? '')) ?: trim((string) ($shipping['phone'] ?? ''));
        $data = array_filter([
            'customer_first_name' => $first,
            'customer_name' => $name,
            'order_number' => (string) ($p['number'] ?? $id),
            'order_total' => ShopifyPayload::money($p['total'] ?? null, (string) ($p['currency'] ?? '')),
            'items' => ShopifyPayload::items($items),
            'first_item' => $items[0]['name'] ?? '',
            'store_name' => $storeName,
        ], fn (string $v) => $v !== '');

        return array_map(fn (string $event) => new StoreEvent(
            event: $event,
            externalId: $id,
            phone: $phone !== '' ? $phone : null,
            country: ((string) ($billing['country'] ?? $shipping['country'] ?? '')) ?: null,
            name: $name !== '' ? $name : null,
            email: ((string) ($billing['email'] ?? '')) ?: null,
            data: $data,
        ), $names);
    }
}
