<?php

/**
 * Signed order events to 10X Engage, with retries and a nightly catch-up.
 *
 * Every change to an order is queued (WooCommerce's Action Scheduler) and sent with the order's
 * latest state: created, paid, completed, refunded (full or partial), cancelled, failed, pending
 * and edits (items or totals). If 10X Engage cannot be reached the event is retried with growing
 * delays for about a day; 10X Engage ignores duplicates (order + event + time). Every night the
 * store's recent orders are compared with what 10X Engage has, and any gaps are sent again.
 *
 * Read-only towards the store: the plugin reads orders and products and never changes them
 * (apart from saving the chat link token on the order at checkout).
 */
defined('ABSPATH') || exit;

final class XEWC_Events
{
    const SEND = 'xewc_send';

    const HEARTBEAT = 'xewc_heartbeat';

    const RECONCILE = 'xewc_reconcile';

    const GROUP = 'xewc';

    /** Retry delays in seconds: 1 min, 5 min, 15 min, 1 h, 3 h, 6 h, 12 h, 24 h. */
    const BACKOFF = [60, 300, 900, 3600, 10800, 21600, 43200, 86400];

    public static function init()
    {
        add_action('woocommerce_new_order', [__CLASS__, 'on_new'], 20, 2);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_status'], 20, 4);
        add_action('woocommerce_order_partially_refunded', [__CLASS__, 'on_refund'], 20, 2);
        add_action('woocommerce_update_order', [__CLASS__, 'on_update'], 20, 2);

        add_action(self::SEND, [__CLASS__, 'send'], 10, 4);
        add_action(self::HEARTBEAT, [__CLASS__, 'heartbeat']);
        add_action(self::RECONCILE, [__CLASS__, 'reconcile']);
        add_action('init', [__CLASS__, 'schedule_recurring'], 20);
    }

    public static function schedule_recurring()
    {
        if (! XEWC_Client::connected() || ! function_exists('as_has_scheduled_action')) {
            return;
        }
        if (as_has_scheduled_action(self::HEARTBEAT, [], self::GROUP) === false) {
            as_schedule_recurring_action(time() + 60, HOUR_IN_SECONDS, self::HEARTBEAT, [], self::GROUP);
        }
        if (as_has_scheduled_action(self::RECONCILE, [], self::GROUP) === false) {
            // Nightly, at a random minute so stores don't all call at once.
            $first = strtotime('tomorrow 02:00') + wp_rand(0, 3 * HOUR_IN_SECONDS);
            as_schedule_recurring_action($first, DAY_IN_SECONDS, self::RECONCILE, [], self::GROUP);
        }
    }

    public static function on_new($order_id, $order = null)
    {
        self::queue((int) $order_id, 'created', time());
    }

    public static function on_status($order_id, $from, $to, $order = null)
    {
        $event = self::event_for((string) $to);
        self::queue((int) $order_id, $event, time());
    }

    public static function on_refund($order_id, $refund_id = 0)
    {
        self::queue((int) $order_id, 'refunded', time());
    }

    /** Edits (items, totals, addresses) — many saves in a row become one event half a minute later. */
    public static function on_update($order_id, $order = null)
    {
        if (! XEWC_Client::connected() || ! function_exists('as_has_scheduled_action')) {
            return;
        }
        $args = [(int) $order_id, 'updated', 0, 0];
        if (as_has_scheduled_action(self::SEND, $args, self::GROUP) === false) {
            as_schedule_single_action(time() + 30, self::SEND, $args, self::GROUP);
        }
    }

    public static function event_for($status)
    {
        $map = [
            'processing' => 'paid',
            'completed' => 'completed',
            'refunded' => 'refunded',
            'cancelled' => 'cancelled',
            'failed' => 'failed',
            'pending' => 'pending',
            'on-hold' => 'pending',
        ];

        return isset($map[$status]) ? $map[$status] : 'updated';
    }

    public static function queue($order_id, $event, $occurred_at, $via = 'plugin')
    {
        if ($order_id <= 0 || ! XEWC_Client::connected()) {
            return;
        }
        $args = [$order_id, $event, (int) $occurred_at, $via === 'catch_up' ? -1 : 0];
        if (function_exists('as_enqueue_async_action')) {
            if (as_has_scheduled_action(self::SEND, $args, self::GROUP) === false) {
                as_enqueue_async_action(self::SEND, $args, self::GROUP);
            }

            return;
        }
        // No Action Scheduler (very old WooCommerce): send at the end of the request.
        add_action(
            'shutdown',
            function () use ($args) {
                XEWC_Events::send($args[0], $args[1], $args[2], $args[3]);
            }
        );
    }

    /**
     * Sends one event with the order's current state. $attempt -1 marks a catch-up resend.
     */
    public static function send($order_id, $event, $occurred_at = 0, $attempt = 0)
    {
        $order = wc_get_order((int) $order_id);
        if (! $order instanceof WC_Order || $order instanceof WC_Order_Refund || $order->get_status() === 'checkout-draft') {
            return; // a block-checkout draft is not an order yet; its first real status is sent
        }
        $via = (int) $attempt === -1 ? 'catch_up' : 'plugin';
        $attempt = max(0, (int) $attempt);
        $when = (int) $occurred_at > 0 ? (int) $occurred_at : ($order->get_date_modified() ? $order->get_date_modified()->getTimestamp() : time());

        $result = XEWC_Client::post(
            'events',
            [
                'events' => [
                    [
                        'event' => (string) $event,
                        'occurred_at' => gmdate('c', $when),
                        'via' => $via,
                        'order' => self::payload($order),
                    ],
                ],
            ]
        );

        $code = is_wp_error($result) ? 0 : (int) $result['code'];
        if ($code >= 200 && $code < 300) {
            return;
        }
        if ($code === 410 || $code === 422) {
            return; // disconnected, or an event 10X Engage can never accept
        }
        if ($attempt < count(self::BACKOFF) && function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + self::BACKOFF[$attempt], self::SEND, [(int) $order_id, (string) $event, $when, $attempt + 1], self::GROUP);
        }
        // After the last retry the nightly catch-up still fills the gap.
    }

    /** The order as 10X Engage reads it: totals, currency, billing phone, line items, the chat token. */
    public static function payload(WC_Order $order)
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            $items[] = [
                'product_id' => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
                'sku' => $product ? (string) $product->get_sku() : '',
                'name' => (string) $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'total' => wc_format_decimal((float) $item->get_total() + (float) $item->get_total_tax(), 2),
            ];
        }
        $date = function ($d) {
            return $d ? gmdate('c', $d->getTimestamp()) : null;
        };
        $meta = function ($key) use ($order) {
            $v = $order->get_meta($key);

            return is_scalar($v) ? (string) $v : '';
        };

        return [
            'id' => (string) $order->get_id(),
            'number' => (string) $order->get_order_number(),
            'status' => (string) $order->get_status(),
            'currency' => (string) $order->get_currency(),
            'total' => wc_format_decimal($order->get_total(), 2),
            'total_refunded' => wc_format_decimal($order->get_total_refunded(), 2),
            'date_created' => $date($order->get_date_created()),
            'date_paid' => $date($order->get_date_paid()),
            'date_completed' => $date($order->get_date_completed()),
            'date_modified' => $date($order->get_date_modified()),
            'created_via' => (string) $order->get_created_via(),
            'billing' => [
                'first_name' => (string) $order->get_billing_first_name(),
                'last_name' => (string) $order->get_billing_last_name(),
                'phone' => (string) $order->get_billing_phone(),
                'country' => (string) $order->get_billing_country(),
            ],
            'shipping' => [
                'phone' => method_exists($order, 'get_shipping_phone') ? (string) $order->get_shipping_phone() : '',
                'country' => (string) $order->get_shipping_country(),
            ],
            'line_items' => $items,
            'xl' => (string) $order->get_meta(XEWC_Link::META),
            // WooCommerce's own order attribution, kept apart by 10X Engage (never overwritten).
            'attribution' => [
                'source_type' => $meta('_wc_order_attribution_source_type'),
                'utm_source' => $meta('_wc_order_attribution_utm_source'),
                'utm_medium' => $meta('_wc_order_attribution_utm_medium'),
                'utm_campaign' => $meta('_wc_order_attribution_utm_campaign'),
                'referrer' => $meta('_wc_order_attribution_referrer'),
            ],
        ];
    }

    public static function heartbeat()
    {
        $latest = wc_get_orders(
            [
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC',
                'type' => 'shop_order',
                'return' => 'objects',
            ]
        );
        $latest_at = ! empty($latest) && $latest[0]->get_date_created() ? gmdate('c', $latest[0]->get_date_created()->getTimestamp()) : null;
        $pending = function_exists('as_get_scheduled_actions')
            ? count(as_get_scheduled_actions(['hook' => self::SEND, 'status' => 'pending', 'group' => self::GROUP, 'per_page' => 500], 'ids'))
            : 0;

        XEWC_Client::post(
            'heartbeat',
            [
                'plugin_version' => XEWC_VERSION,
                'wc_version' => defined('WC_VERSION') ? WC_VERSION : '',
                'wp_version' => get_bloginfo('version'),
                'currency' => get_woocommerce_currency(),
                'hpos' => XEWC_Client::hpos(),
                'block_checkout' => XEWC_Client::block_checkout(),
                'latest_order_at' => $latest_at,
                'queue_pending' => $pending,
            ]
        );
    }

    /** Nightly catch-up: orders changed in the last three days that 10X Engage is missing or has older. */
    public static function reconcile()
    {
        $ids = wc_get_orders(
            [
                'limit' => 1000,
                'type' => 'shop_order',
                'date_modified' => '>='.(time() - 3 * DAY_IN_SECONDS),
                'return' => 'ids',
            ]
        );
        if (empty($ids)) {
            return;
        }
        $manifest = [];
        foreach ($ids as $id) {
            $order = wc_get_order($id);
            if ($order instanceof WC_Order && $order->get_date_modified()) {
                $manifest[] = ['id' => (string) $id, 'modified' => gmdate('c', $order->get_date_modified()->getTimestamp())];
            }
        }
        $result = XEWC_Client::post('reconcile', ['orders' => $manifest]);
        if (is_wp_error($result) || ! is_array($result['data']) || empty($result['data']['resend'])) {
            return;
        }
        foreach ((array) $result['data']['resend'] as $id) {
            $order = wc_get_order((int) $id);
            if ($order instanceof WC_Order) {
                self::queue((int) $id, self::event_for($order->get_status()), $order->get_date_modified() ? $order->get_date_modified()->getTimestamp() : time(), 'catch_up');
            }
        }
    }
}
