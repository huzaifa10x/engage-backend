<?php

/**
 * The chat link token (?xl=…) and the website tracker.
 *
 * Links sent from a 10X Engage chat carry a signed token. When a visitor arrives with one, it is
 * kept in the WooCommerce session and in a first-party cookie, so it survives logging in, the cart
 * and redirects to a payment page and back. At checkout (classic or block checkout) it is saved on
 * the order. 10X Engage checks it: it only attaches an order in its own workspace, once, before it
 * expires, and only when the order's phone matches the chat.
 */
defined('ABSPATH') || exit;

final class XEWC_Link
{
    const COOKIE = 'xewc_link';

    const SESSION = 'xewc_link';

    const META = '_xe_link_token';

    const PATTERN = '/^[A-Za-z0-9]{24}$/';

    public static function init()
    {
        add_action('template_redirect', [__CLASS__, 'capture'], 1);
        add_action('wp_head', [__CLASS__, 'tracker'], 1);

        // Classic checkout.
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_on_order'], 10, 1);
        // Block checkout (Store API): the order exists before payment; save the token as it is updated.
        add_action('woocommerce_store_api_checkout_update_order_from_request', [__CLASS__, 'save_on_order'], 10, 1);
        // Anything else that creates an order in the visitor's session (some payment plugins).
        add_action('woocommerce_new_order', [__CLASS__, 'save_on_new_order'], 5, 2);
        // Paid: the link has done its job for this visitor.
        add_action('woocommerce_thankyou', [__CLASS__, 'forget'], 20);
    }

    /** The parameter name, from 10X Engage (xl). */
    public static function param()
    {
        $c = XEWC_Client::connection();

        return $c !== null && ! empty($c['link_param']) ? $c['link_param'] : 'xl';
    }

    public static function capture()
    {
        if (is_admin() || ! XEWC_Client::connected()) {
            return;
        }
        $param = self::param();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a link from a chat, not a form.
        $token = isset($_GET[$param]) ? sanitize_text_field(wp_unslash($_GET[$param])) : '';
        if (! preg_match(self::PATTERN, $token)) {
            return;
        }
        self::remember($token);
    }

    public static function remember($token)
    {
        $c = XEWC_Client::connection();
        $days = $c !== null && ! empty($c['link_days']) ? (int) $c['link_days'] : 14;
        if (function_exists('WC') && WC()->session) {
            if (! WC()->session->has_session()) {
                WC()->session->set_customer_session_cookie(true);
            }
            WC()->session->set(self::SESSION, ['token' => $token, 'at' => time()]);
        }
        if (! headers_sent()) {
            setcookie(
                self::COOKIE,
                $token,
                [
                    'expires' => time() + $days * DAY_IN_SECONDS,
                    'path' => COOKIEPATH ? COOKIEPATH : '/',
                    'domain' => COOKIE_DOMAIN,
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }
        $_COOKIE[self::COOKIE] = $token;
    }

    /** The visitor's token: the session first (logged-in and guest carts), then the cookie. */
    public static function current()
    {
        if (function_exists('WC') && WC()->session) {
            $s = WC()->session->get(self::SESSION);
            if (is_array($s) && ! empty($s['token']) && preg_match(self::PATTERN, $s['token'])) {
                return $s['token'];
            }
        }
        $cookie = isset($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) : '';

        return preg_match(self::PATTERN, $cookie) ? $cookie : null;
    }

    /** @param WC_Order $order */
    public static function save_on_order($order)
    {
        if (! $order instanceof WC_Order || (string) $order->get_meta(self::META) !== '') {
            return;
        }
        $token = self::current();
        if ($token !== null) {
            $order->update_meta_data(self::META, $token);
        }
    }

    public static function save_on_new_order($order_id, $order = null)
    {
        if (is_admin() && ! wp_doing_ajax()) {
            return; // an order created by staff in wp-admin is not the visitor's
        }
        $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
        if (! $order instanceof WC_Order || (string) $order->get_meta(self::META) !== '') {
            return;
        }
        $token = self::current();
        if ($token !== null) {
            $order->update_meta_data(self::META, $token);
            $order->save_meta_data();
        }
    }

    public static function forget()
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->__unset(self::SESSION);
        }
        if (! headers_sent() && isset($_COOKIE[self::COOKIE])) {
            setcookie(self::COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
        }
        unset($_COOKIE[self::COOKIE]);
    }

    /** The R1 website tracker, installed automatically (no snippet to paste). */
    public static function tracker()
    {
        $c = XEWC_Client::connection();
        if ($c === null || empty($c['tracker_src']) || is_admin()) {
            return;
        }
        printf("<script async src=\"%s\"></script>\n", esc_url($c['tracker_src'])); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- must load early and async, like the snippet.
    }
}
