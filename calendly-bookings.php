<?php
/**
 * Plugin Name: Calendly Bookings
 * Plugin URI: https://github.com/whashby/calendly-bookings
 * Description: A CMS for managing Calendly events, clients and WooCommerce products.
 * Version: 6.9.4
 * Requires at least: 5.2
 * Requires PHP: 8.3
 * Author:      Wafiq Harris-Ashby
 * Author URI:  https://whashby.github.io
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: calendly-bookings
 * Update URI: false
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
 * GitHub updater bootstrap.
 */
add_action('init', function () {
    new CB_GitHub_Updater(__FILE__);
});

/**
 * Schedule 5-minute cron on activation.
 */
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('cb_sync_scheduled_events_cron')) {
        wp_schedule_event(time(), 'every_5_minutes', 'cb_sync_scheduled_events_cron');
    }

    // Run installer on activation (schema + meeting page).
    \Calendly_Bookings\CB_Installer::activate();
});

/**
 * Clear cron and uninstall hooks on deactivation.
 */
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('cb_sync_scheduled_events_cron');
});

/**
 * Uninstall hook.
 */
register_uninstall_hook(__FILE__, ['Calendly_Bookings\CB_Installer', 'uninstall']);
