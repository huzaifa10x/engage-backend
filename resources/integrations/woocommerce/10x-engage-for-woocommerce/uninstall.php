<?php

/**
 * Removes the plugin's settings. Order notes and the chat link token saved on past orders stay
 * (they are part of the order's history).
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('xewc_connection');
delete_option('xewc_status');
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('xewc_send', [], 'xewc');
    as_unschedule_all_actions('xewc_heartbeat', [], 'xewc');
    as_unschedule_all_actions('xewc_reconcile', [], 'xewc');
}
