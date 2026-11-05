<?php


use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * Plugin Name:       10X Engage for WooCommerce
 * Plugin URI:        https://10xdigital.ae
 * Description:       Connects your store to 10X Engage: installs the website tracker, keeps the chat link on the order through checkout, and sends signed order updates so you can see which WhatsApp chats became orders.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            10X Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       xewc
 * WC requires at least: 7.6
 * WC tested up to:   9.5
 */
defined('ABSPATH') || exit;

define('XEWC_VERSION', '1.0.0');
define('XEWC_FILE', __FILE__);
define('XEWC_DIR', plugin_dir_path(__FILE__));

require_once XEWC_DIR.'includes/class-xewc-client.php';
require_once XEWC_DIR.'includes/class-xewc-link.php';
require_once XEWC_DIR.'includes/class-xewc-events.php';
require_once XEWC_DIR.'includes/class-xewc-admin.php';

// Works with High-Performance Order Storage and the block checkout.
add_action(
    'before_woocommerce_init',
    function () {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            FeaturesUtil::declare_compatibility('custom_order_tables', XEWC_FILE, true);
            FeaturesUtil::declare_compatibility('cart_checkout_blocks', XEWC_FILE, true);
        }
    }
);

add_action(
    'plugins_loaded',
    function () {
        if (! class_exists('WooCommerce')) {
            add_action(
                'admin_notices',
                function () {
                    echo '<div class="notice notice-error"><p>'.esc_html__('10X Engage for WooCommerce needs WooCommerce to be installed and active.', 'xewc').'</p></div>';
                }
            );

            return;
        }
        XEWC_Link::init();
        XEWC_Events::init();
        XEWC_Admin::init();
    }
);

register_deactivation_hook(
    __FILE__,
    function () {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(XEWC_Events::HEARTBEAT, [], 'xewc');
            as_unschedule_all_actions(XEWC_Events::RECONCILE, [], 'xewc');
        }
    }
);
