<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Services;

use App\Domain\Developer\Services\UrlGuard;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Infrastructure\Secrets\SecretStore;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Order lookup block: the status of one of the customer's own orders. Status only — never
 * someone else's order: the order must belong to the customer's phone number.
 *
 * WooCommerce stores are asked live (the connection keeps API keys). Shopify connections keep no
 * API token, so for Shopify (and as a fallback) the order events the store sent us are used.
 */
final class OrderLookup
{
    private const STATUS = [
        'order_created' => 'Received',
        'order_fulfilled' => 'Shipped',
        'order_cancelled' => 'Cancelled',
    ];

    public function __construct(private readonly SecretStore $secrets, private readonly UrlGuard $guard) {}

    public static function connected(): bool
    {
        return Integration::query()->whereIn('provider', [Integration::SHOPIFY, Integration::WOOCOMMERCE])->where('status', 'active')->exists();
    }

    /**
     * @param  ?string  $phone  the customer's number (+9715…)
     * @return ?array<string, mixed> number, status, total, updated_at, tracking_url, tracking_number, items, link
     */
    public function find(string $number, ?string $phone, ?string $contactId): ?array
    {
        $number = ltrim(trim($number), '#');
        if ($number === '' || mb_strlen($number) > 40 || ($phone === null && $contactId === null)) {
            return null;
        }

        foreach (Integration::query()->where('provider', Integration::WOOCOMMERCE)->where('status', 'active')->get() as $store) {
            $found = $this->woo($store, $number, $phone);
            if ($found !== null) {
                return $found;
            }
        }

        $events = IntegrationEvent::query()->whereIn('event', array_keys(self::STATUS))
            ->where(fn ($q) => $q->where('data->order_number', $number)->orWhere('data->order_number', '#'.$number))
            ->latest('created_at')->limit(10)->get();
        foreach ($events as $event) {
            if (! $this->samePerson($event->phone, $phone) && ($contactId === null || $event->contact_id !== $contactId)) {
                continue;
            }
            $d = (array) ($event->data ?? []);

            return array_filter([
                'number' => $number,
                'status' => self::STATUS[$event->event],
                'total' => $d['order_total'] ?? null,
                'items' => $d['items'] ?? null,
                'tracking_number' => $d['tracking_number'] ?? null,
                'tracking_company' => $d['tracking_company'] ?? null,
                'tracking_url' => $d['tracking_url'] ?? null,
                'link' => $d['order_status_url'] ?? null,
                'updated_at' => $event->created_at?->toIso8601String(),
            ], fn ($v) => $v !== null && $v !== '');
        }

        return null;
    }

    /** @return ?array<string, mixed> */
    private function woo(Integration $store, string $number, ?string $phone): ?array
    {
        if ($store->secret_id === null || ! ctype_digit($number)) {
            return null;
        }
        try {
            $keys = (array) json_decode($this->secrets->get($store->secret_id), true);
            $base = rtrim((string) $store->setting('base_url'), '/');
            $this->guard->assertPublic($base);
            $response = Http::withBasicAuth((string) ($keys['key'] ?? ''), (string) ($keys['secret'] ?? ''))->acceptJson()->timeout(6)
                ->withOptions(['allow_redirects' => false])->get($base.'/wp-json/wc/v3/orders/'.(int) $number);
            if (! $response->successful()) {
                return null;
            }
            $o = (array) $response->json();
        } catch (Throwable) {
            return null;
        }
        if (! $this->samePerson((string) ($o['billing']['phone'] ?? ''), $phone)) {
            return null;
        }

        return array_filter([
            'number' => (string) ($o['number'] ?? $number),
            'status' => ucwords(str_replace('-', ' ', (string) ($o['status'] ?? 'unknown'))),
            'total' => isset($o['total']) ? trim(($o['currency'] ?? '').' '.$o['total']) : null,
            'items' => implode(', ', array_map(fn ($i) => ($i['quantity'] ?? 1).' × '.($i['name'] ?? ''), array_slice((array) ($o['line_items'] ?? []), 0, 5))) ?: null,
            'updated_at' => $o['date_modified_gmt'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Same phone number, written with or without the country code (the last 9 digits match). */
    private function samePerson(?string $a, ?string $b): bool
    {
        $da = substr((string) preg_replace('/\D/', '', (string) $a), -9);
        $db = substr((string) preg_replace('/\D/', '', (string) $b), -9);

        return strlen($da) === 9 && $da === $db;
    }
}
