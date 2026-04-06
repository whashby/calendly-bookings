<?php
/**
 * Plugin Name: Calendly Bookings
 * Plugin URI: https://github.com/whashby/calendly-bookings
 * Description: A CMS for managing Calendly events, clients and WooCommerce products.
 * Version: 6.9.110
 * Requires at least: 5.2
 * Requires PHP: 8.3
 * Author:      Wafiq Harris-Ashby
 * Author URI:  https://whashby.github.io
 * Icon URI: assets/cb-icon.svg
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
 * Schedule 5-minute cron on activation.
 */
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('cb_sync_master_cron')) {
        wp_schedule_event(time(), 'every_5_minutes', 'cb_sync_master_cron');
    }

    // Run installer on activation (schema + meeting page).
    \Calendly_Bookings\CB_Installer::activate();
});

/**
 * Clear cron and uninstall hooks on deactivation.
 */
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('cb_sync_master_cron');
    wp_clear_scheduled_hook('cb_sync_scheduled_events_cron');
    wp_clear_scheduled_hook('cb_sync_invitees_cron');
    wp_clear_scheduled_hook('cb_sync_event_types_cron');
    wp_clear_scheduled_hook('cb_sync_locations_cron');
});

// Cron callbacks
add_action('cb_sync_master_cron', function () {
    $api = CB_API::instance();
    $min_start = get_option(CB_Constants::OPT_MIN_START_DATE);

    $api->sync($min_start, true);
    $api->sync_invitees($min_start, true);
    $api->sync_event_types($min_start, true);
    $api->sync_locations($min_start, true);

    update_option('cb_last_sync_all', current_time('mysql'));
});
/**
 * Uninstall hook.
 */
register_uninstall_hook(__FILE__, ['Calendly_Bookings\CB_Installer', 'uninstall']);
