<?php
/**
 * Plugin Name: Calendly Bookings
 * Plugin URI: https://github.com/whashby/calendly-bookings
 * Description: A CMS for managing Calendly events, clients and WooCommerce products.
 * Version: 6.9.134
 * Requires at least: 5.2
 * Requires PHP: 8.3
 * Author:      Wafiq Harris-Ashby
 * Author URI:  https://whashby.github.io
 * Icon URI: https://github.com/whashby/calendly-bookings/assets/cb-icon.svg
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: calendly-bookings
 * Update URI: https://github.com/whashby/calendly-bookings/releases
 * GitHub Plugin URI: https://github.com/whashby/calendly-bookings
 * GitHub Release Asset: true
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/constants.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/updater.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * Handle manual token refresh.
 */
add_action('admin_post_cb_refresh_github_token', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized', 'calendly-bookings'));
    }

    check_admin_referer('cb_refresh_github_token');

    global $cb_github_updater;
    if ($cb_github_updater instanceof CB_GitHub_Updater) {
        $cb_github_updater->refresh_token();
    }

    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit;
});

/**
 * GitHub updater bootstrap.
 */
add_action('init', function () {
    \Calendly_Bookings\CB_GitHub_Updater::instance(__FILE__);
});

/**
 * Uninstall hook.
 */
register_uninstall_hook(__FILE__, ['Calendly_Bookings\CB_Installer', 'uninstall']);
