<?php
/**
 * Plugin Name: Calendly Bookings
 * Plugin URI: https://github.com/whashby/calendly-bookings
 * Description: A CMS for managing Calendly events, clients and WooCommerce products.
 * Version: 6.9.222
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

require_once ABSPATH . 'wp-admin/includes/plugin.php';

// --- WooCommerce Dependency Check ---
register_activation_hook(__FILE__, function () {
    if (!is_plugin_active('woocommerce/woocommerce.php')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('Calendly Bookings requires WooCommerce to be installed and active. Please install and activate WooCommerce first.', 'calendly-bookings'),
            __('Plugin dependency check', 'calendly-bookings'),
            ['back_link' => true]
        );
    }
});

// Runtime check: show admin notice if WooCommerce is deactivated later
add_action('admin_init', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            $install_url = admin_url('plugin-install.php?s=woocommerce&tab=search&type=term');
            echo '<div class="notice notice-error"><p>'
                . __('Calendly Bookings requires WooCommerce. Please install and activate WooCommerce.', 'calendly-bookings')
                . '</p><p><a href="' . esc_url($install_url) . '" class="button button-primary">'
                . __('Install WooCommerce', 'calendly-bookings') . '</a></p></div>';
        });
    }
});
// --- Plugin bootstrap ---
require_once __DIR__ . '/includes/constants.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/updater.php';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

add_action('admin_init', function() {
    register_setting(CB_Constants::OPT_GROUP, 'cb_report_fields');
    register_setting(CB_Constants::OPT_GROUP, 'cb_report_filetype');
    register_setting(CB_Constants::OPT_GROUP, 'cb_report_start');
    register_setting(CB_Constants::OPT_GROUP, 'cb_report_end');
    register_setting(CB_Constants::OPT_GROUP, 'cb_product_start');
    register_setting(CB_Constants::OPT_GROUP, 'cb_product_end');
    register_setting(CB_Constants::OPT_GROUP, 'cb_discount_start');
    register_setting(CB_Constants::OPT_GROUP, 'cb_discount_end');
    register_setting(CB_Constants::OPT_GROUP, 'cb_stats_start');
    register_setting(CB_Constants::OPT_GROUP, 'cb_stats_end');
});

/**
 * Handle manual token refresh.
 */
add_action('admin_post_cb_refresh_github_token', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized', 'calendly-bookings'));
    }

    check_admin_referer('cb_refresh_github_token');

    global $cb_github_updater;
    if ($cb_github_updater instanceof \Calendly_Bookings\CB_GitHub_Updater) {
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
