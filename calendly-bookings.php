<?php
/**
 * Plugin Name: Calendly Bookings
 * Plugin URI: https://github.com/whashby/calendly-bookings
 * Description: A CMS for managing Calendly events, clients and WooCommerce products.
 * Version: 6.9.37
 * Requires at least: 5.2
 * Requires PHP: 8.3
 * Author:      Wafiq Harris-Ashby
 * Author URI:  https://whashby.github.io
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: calendly-bookings
 * Update URI: https://github.com/whashby/calendly-bookings
 * GitHub Plugin URI: https://github.com/whashby/calendly-bookings
 * GitHub Release Asset: true
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CB_LICENSE_OPTION', 'calendly_bookings_license_key');
define('CB_TOKEN_OPTION', 'calendly_bookings_encrypted_token');
define('CB_WORKER_ENDPOINT', 'https://calendly-bookings.whashby.workers.dev');

require_once __DIR__ . '/includes/constants.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/updater.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * Register license key setting on Settings → General.
 */
add_action('admin_init', function () {
    register_setting(
        'general',
        CB_LICENSE_OPTION,
        [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]
    );

    add_settings_field(
        CB_LICENSE_OPTION,
        __('Calendly Bookings License Key', 'calendly-bookings'),
        function () {
            $value = get_option(CB_LICENSE_OPTION, '');
            ?>
            <input type="text" id="<?php echo esc_attr(CB_LICENSE_OPTION); ?>" name="<?php echo esc_attr(CB_LICENSE_OPTION); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text"/>
            <p class="description">
                <?php esc_html_e('Enter your license key to enable private GitHub updates.', 'calendly-bookings'); ?>
            </p>
            <?php
        },
        'general'
    );
});

/**
 * Show a small notice with a "Refresh token" link when a license is present.
 */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    $license = get_option(CB_LICENSE_OPTION);
    if (empty($license)) {
        return;
    }

    $url = wp_nonce_url(
        admin_url('admin-post.php?action=cb_refresh_github_token'),
        'cb_refresh_github_token'
    );
    ?>
    <div class="notice notice-info">
        <p>
            <?php esc_html_e('Calendly Bookings license is set.', 'calendly-bookings'); ?>
            <a href="<?php echo esc_url($url); ?>">
                <?php esc_html_e('Refresh GitHub token now', 'calendly-bookings'); ?>
            </a>
        </p>
    </div>
    <?php
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
    global $cb_github_updater;
    $cb_github_updater = new CB_GitHub_Updater(__FILE__);
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
