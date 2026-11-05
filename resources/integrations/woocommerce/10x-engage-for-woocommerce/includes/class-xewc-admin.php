<?php
/**
 * WooCommerce → 10X Engage: paste the workspace key, see the connection.
 */
defined('ABSPATH') || exit;

final class XEWC_Admin
{
    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'menu'], 60);
        add_action('admin_post_xewc_connect', [__CLASS__, 'connect']);
        add_action('admin_post_xewc_disconnect', [__CLASS__, 'disconnect']);
        add_action('admin_post_xewc_sync', [__CLASS__, 'sync']);
        add_filter('plugin_action_links_'.plugin_basename(XEWC_FILE), [__CLASS__, 'links']);
    }

    public static function menu()
    {
        add_submenu_page('woocommerce', __('10X Engage', 'xewc'), __('10X Engage', 'xewc'), 'manage_woocommerce', 'xewc', [__CLASS__, 'page']);
    }

    public static function links($links)
    {
        array_unshift($links, '<a href="'.esc_url(admin_url('admin.php?page=xewc')).'">'.esc_html__('Settings', 'xewc').'</a>');

        return $links;
    }

    public static function page()
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        $c = XEWC_Client::connection();
        $status = get_option('xewc_status', []);
        $notice = isset($_GET['xewc']) ? sanitize_key(wp_unslash($_GET['xewc'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $error = get_transient('xewc_error_'.get_current_user_id());
        delete_transient('xewc_error_'.get_current_user_id());
        $pending = function_exists('as_get_scheduled_actions')
            ? count(as_get_scheduled_actions(['hook' => XEWC_Events::SEND, 'status' => 'pending', 'group' => XEWC_Events::GROUP, 'per_page' => 500], 'ids'))
            : 0;
        ?>
		<div class="wrap">
			<h1><?php esc_html_e('10X Engage', 'xewc'); ?></h1>
			<p><?php esc_html_e('See which WhatsApp chats became orders. Links sent from a 10X Engage chat carry a token that is saved on the order at checkout, and every order change is sent to 10X Engage, signed.', 'xewc'); ?></p>

			<?php if ($error) { ?>
				<div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
			<?php } elseif ($notice === 'connected') { ?>
				<div class="notice notice-success"><p><?php esc_html_e('Connected. The store now shows as Connected in 10X Engage → Integrations.', 'xewc'); ?></p></div>
			<?php } elseif ($notice === 'synced') { ?>
				<div class="notice notice-success"><p><?php esc_html_e('Recent orders are being checked and any missing ones sent again.', 'xewc'); ?></p></div>
			<?php } ?>

			<?php if ($c === null) { ?>
				<h2><?php esc_html_e('Connect', 'xewc'); ?></h2>
				<ol>
					<li><?php esc_html_e('In 10X Engage, open Integrations → WooCommerce and choose “Get workspace key”.', 'xewc'); ?></li>
					<li><?php esc_html_e('Paste the key here and choose Connect.', 'xewc'); ?></li>
				</ol>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('xewc_connect'); ?>
					<input type="hidden" name="action" value="xewc_connect" />
					<p><textarea name="xewc_key" rows="3" class="large-text code" placeholder="xewc_…" required></textarea></p>
					<?php submit_button(__('Connect', 'xewc')); ?>
				</form>
			<?php } else { ?>
				<table class="widefat striped" style="max-width:720px">
					<tbody>
						<tr><th><?php esc_html_e('Status', 'xewc'); ?></th><td>
							<?php
                            $state = isset($status['state']) ? $status['state'] : 'ok';
			    if ($state === 'disconnected') {
			        echo '<strong style="color:#b32d2e">'.esc_html__('Disconnected in 10X Engage', 'xewc').'</strong> — '.esc_html($status['message']);
			    } elseif ($state === 'error') {
			        echo '<strong style="color:#996800">'.esc_html__('Retrying', 'xewc').'</strong> — '.esc_html($status['message']);
			    } else {
			        echo '<strong style="color:#008a20">'.esc_html__('Connected', 'xewc').'</strong>';
			    }
			    ?>
						</td></tr>
						<tr><th><?php esc_html_e('Workspace store', 'xewc'); ?></th><td><?php echo esc_html($c['name'] ? $c['name'] : $c['store_id']); ?></td></tr>
						<tr><th><?php esc_html_e('Last successful send', 'xewc'); ?></th><td><?php echo ! empty($status['last_ok']) ? esc_html(human_time_diff((int) $status['last_ok']).' '.__('ago', 'xewc')) : esc_html__('Nothing sent yet', 'xewc'); ?></td></tr>
						<tr><th><?php esc_html_e('Waiting to send', 'xewc'); ?></th><td><?php echo esc_html((string) $pending); ?></td></tr>
						<tr><th><?php esc_html_e('Website tracker', 'xewc'); ?></th><td><?php echo ! empty($c['tracker_src']) ? esc_html__('Installed on every page', 'xewc') : esc_html__('Not used (no Growth Hub website for this store)', 'xewc'); ?></td></tr>
						<tr><th><?php esc_html_e('Chat links', 'xewc'); ?></th><td><?php echo esc_html(sprintf( /* translators: 1: parameter, 2: days */ __('Token ?%1$s= kept through checkout for %2$d days', 'xewc'), $c['link_param'], (int) $c['link_days'])); ?></td></tr>
						<tr><th><?php esc_html_e('Order storage', 'xewc'); ?></th><td><?php echo XEWC_Client::hpos() ? esc_html__('High-Performance Order Storage', 'xewc') : esc_html__('WordPress posts storage', 'xewc'); ?> · <?php echo XEWC_Client::block_checkout() ? esc_html__('Block checkout', 'xewc') : esc_html__('Classic checkout', 'xewc'); ?></td></tr>
					</tbody>
				</table>
				<div style="display:flex;gap:8px;margin-top:12px">
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('xewc_sync'); ?>
						<input type="hidden" name="action" value="xewc_sync" />
						<?php submit_button(__('Check recent orders now', 'xewc'), 'secondary', 'submit', false); ?>
					</form>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Disconnect this store from 10X Engage?', 'xewc')); ?>');">
						<?php wp_nonce_field('xewc_disconnect'); ?>
						<input type="hidden" name="action" value="xewc_disconnect" />
						<?php submit_button(__('Disconnect', 'xewc'), 'delete', 'submit', false); ?>
					</form>
				</div>
			<?php } ?>
		</div>
		<?php
    }

    public static function connect()
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do this.', 'xewc'));
        }
        check_admin_referer('xewc_connect');
        $key = isset($_POST['xewc_key']) ? sanitize_textarea_field(wp_unslash($_POST['xewc_key'])) : '';
        $result = XEWC_Client::connect(preg_replace('/\s+/', '', $key));
        if (is_wp_error($result)) {
            set_transient('xewc_error_'.get_current_user_id(), $result->get_error_message(), 60);
            wp_safe_redirect(admin_url('admin.php?page=xewc'));
            exit;
        }
        XEWC_Events::schedule_recurring();
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(XEWC_Events::HEARTBEAT, [], XEWC_Events::GROUP);
            as_enqueue_async_action(XEWC_Events::RECONCILE, [], XEWC_Events::GROUP);
        }
        wp_safe_redirect(admin_url('admin.php?page=xewc&xewc=connected'));
        exit;
    }

    public static function sync()
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do this.', 'xewc'));
        }
        check_admin_referer('xewc_sync');
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(XEWC_Events::RECONCILE, [], XEWC_Events::GROUP);
            as_enqueue_async_action(XEWC_Events::HEARTBEAT, [], XEWC_Events::GROUP);
        }
        wp_safe_redirect(admin_url('admin.php?page=xewc&xewc=synced'));
        exit;
    }

    public static function disconnect()
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do this.', 'xewc'));
        }
        check_admin_referer('xewc_disconnect');
        XEWC_Client::disconnect();
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(XEWC_Events::HEARTBEAT, [], XEWC_Events::GROUP);
            as_unschedule_all_actions(XEWC_Events::RECONCILE, [], XEWC_Events::GROUP);
            as_unschedule_all_actions(XEWC_Events::SEND, [], XEWC_Events::GROUP);
        }
        wp_safe_redirect(admin_url('admin.php?page=xewc'));
        exit;
    }
}
