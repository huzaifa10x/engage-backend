<?php


use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Talks to 10X Engage: connect with the workspace key, then signed requests.
 *
 * Every request after connecting is signed with the store's own secret:
 *   X-Engage-Timestamp: unix time
 *   X-Engage-Signature: base64( HMAC-SHA256( timestamp + "." + body, secret ) )
 * 10X Engage refuses requests older than five minutes and ignores repeated events.
 */
defined('ABSPATH') || exit;

final class XEWC_Client
{
    const OPTION = 'xewc_connection';

    /** @return array{api:string,store_id:string,secret:string,name:string,tracker_src:?string,link_param:string,link_days:int,connected_at:int}|null */
    public static function connection()
    {
        $c = get_option(self::OPTION);

        return is_array($c) && ! empty($c['api']) && ! empty($c['store_id']) && ! empty($c['secret']) ? $c : null;
    }

    public static function connected()
    {
        return self::connection() !== null;
    }

    /**
     * The workspace key from Integrations → WooCommerce: "xewc_" + base64url({u: API address, i: store ID, s: one-time secret}).
     *
     * @return array{u:string,i:string,s:string}|null
     */
    public static function decode_key($key)
    {
        $key = trim((string) $key);
        if (strpos($key, 'xewc_') !== 0) {
            return null;
        }
        $json = base64_decode(strtr(substr($key, 5), '-_', '+/'), true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $data = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($data) || empty($data['u']) || empty($data['i']) || empty($data['s'])) {
            return null;
        }
        if (strpos($data['u'], 'https://') !== 0 && strpos($data['u'], 'http://localhost') !== 0 && strpos($data['u'], 'http://127.0.0.1') !== 0) {
            return null;
        }

        return ['u' => untrailingslashit($data['u']), 'i' => (string) $data['i'], 's' => (string) $data['s']];
    }

    /** @return true|WP_Error */
    public static function connect($key)
    {
        $decoded = self::decode_key($key);
        if ($decoded === null) {
            return new WP_Error('xewc_key', __('This is not a 10X Engage workspace key. Copy it again from Integrations → WooCommerce in 10X Engage.', 'xewc'));
        }
        $body = wp_json_encode(
            [
                'key' => trim((string) $key),
                'site_url' => home_url('/'),
                'name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                'plugin_version' => XEWC_VERSION,
                'wc_version' => defined('WC_VERSION') ? WC_VERSION : '',
                'wp_version' => get_bloginfo('version'),
                'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
                'hpos' => self::hpos(),
                'block_checkout' => self::block_checkout(),
            ]
        );
        $response = wp_remote_post(
            $decoded['u'].'/api/integrations/woocommerce/plugin/connect',
            [
                'timeout' => 20,
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body' => $body,
            ]
        );
        if (is_wp_error($response)) {
            return new WP_Error('xewc_network', sprintf( /* translators: %s: error */ __('10X Engage could not be reached: %s', 'xewc'), $response->get_error_message()));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code !== 201 || empty($data['data']['store_id']) || empty($data['data']['secret'])) {
            $message = is_array($data) && ! empty($data['message']) ? $data['message'] : sprintf( /* translators: %d: HTTP status */ __('10X Engage answered with an error (%d).', 'xewc'), $code);

            return new WP_Error('xewc_connect', $message);
        }
        $d = $data['data'];
        update_option(
            self::OPTION,
            [
                'api' => $decoded['u'],
                'store_id' => (string) $d['store_id'],
                'secret' => (string) $d['secret'],
                'name' => isset($d['name']) ? (string) $d['name'] : '',
                'tracker_src' => self::clean_src(isset($d['tracker_src']) ? $d['tracker_src'] : null, $decoded['u']),
                'link_param' => self::clean_param(isset($d['link_param']) ? $d['link_param'] : 'xl'),
                'link_days' => isset($d['link_days']) ? max(1, min(90, (int) $d['link_days'])) : 14,
                'connected_at' => time(),
            ],
            false
        );

        return true;
    }

    public static function disconnect()
    {
        delete_option(self::OPTION);
        delete_option('xewc_status');
    }

    /**
     * A signed POST to the store's endpoints.
     *
     * @return array{code:int,data:mixed}|WP_Error
     */
    public static function post($path, array $payload)
    {
        $c = self::connection();
        if ($c === null) {
            return new WP_Error('xewc_not_connected', __('Not connected to 10X Engage.', 'xewc'));
        }
        $body = wp_json_encode($payload);
        $timestamp = (string) time();
        $signature = base64_encode(hash_hmac('sha256', $timestamp.'.'.$body, $c['secret'], true)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        $response = wp_remote_post(
            $c['api'].'/api/integrations/woocommerce/'.rawurlencode($c['store_id']).'/plugin/'.$path,
            [
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Engage-Timestamp' => $timestamp,
                    'X-Engage-Signature' => $signature,
                ],
                'body' => $body,
            ]
        );
        if (is_wp_error($response)) {
            self::status('error', $response->get_error_message());

            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code === 410) {
            // Disconnected in 10X Engage: stop sending until connected again.
            self::status('disconnected', is_array($data) && ! empty($data['message']) ? $data['message'] : __('The store was disconnected in 10X Engage.', 'xewc'));
        } elseif ($code >= 200 && $code < 300) {
            self::status('ok', '');
            if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
                self::sync_settings($data['data']);
            }
        } else {
            self::status('error', sprintf('HTTP %d', $code));
        }

        // 429 / 503: 10X Engage says when to come back.
        $retry = (int) wp_remote_retrieve_header($response, 'retry-after');

        return ['code' => $code, 'data' => is_array($data) && array_key_exists('data', $data) ? $data['data'] : null, 'retry_after' => max(0, min(3600, $retry))];
    }

    /** The tracker and link settings come back with heartbeats, so changes in 10X Engage reach the store. */
    public static function sync_settings(array $d)
    {
        $c = self::connection();
        if ($c === null) {
            return;
        }
        $changed = false;
        if (array_key_exists('tracker_src', $d)) {
            $c['tracker_src'] = self::clean_src($d['tracker_src'], $c['api']);
            $changed = true;
        }
        if (isset($d['link_param'])) {
            $c['link_param'] = self::clean_param($d['link_param']);
            $changed = true;
        }
        if (isset($d['link_days'])) {
            $c['link_days'] = max(1, min(90, (int) $d['link_days']));
            $changed = true;
        }
        if ($changed) {
            update_option(self::OPTION, $c, false);
        }
    }

    public static function status($state, $message)
    {
        $s = get_option('xewc_status', []);
        $s = is_array($s) ? $s : [];
        $s['state'] = $state;
        $s['message'] = (string) $message;
        $s['at'] = time();
        if ($state === 'ok') {
            $s['last_ok'] = time();
        }
        update_option('xewc_status', $s, false);
    }

    public static function hpos()
    {
        return class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && OrderUtil::custom_orders_table_usage_is_enabled();
    }

    public static function block_checkout()
    {
        if (! function_exists('wc_get_page_id') || ! function_exists('has_block')) {
            return false;
        }
        $page = wc_get_page_id('checkout');

        return $page > 0 && has_block('woocommerce/checkout', $page);
    }

    /** Only a script from our own tracker host, by https. */
    private static function clean_src($src, $api)
    {
        if (! is_string($src) || $src === '' || strpos($src, 'https://') !== 0) {
            return null;
        }
        $path = wp_parse_url($src, PHP_URL_PATH);

        return is_string($path) && $path === '/sdk/v1/e.min.js' ? esc_url_raw($src) : null;
    }

    private static function clean_param($param)
    {
        $param = (string) $param;

        return preg_match('/^[a-z]{1,8}$/', $param) ? $param : 'xl';
    }
}
