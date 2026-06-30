<?php

namespace Calendly_Bookings;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;
use Calendly_Bookings\Modules\CB_Plugin;
use Calendly_Bookings\Modules\CB_API;

require_once __DIR__ . '/installer.php';
require_once __DIR__ . '/utils/functions.php';
require_once __DIR__ . '/utils/class-cb-timezone-converter.php';
require_once __DIR__ . '/utils/class-cb-encryption.php';
require_once __DIR__ . '/utils/class-cb-timezone-converter.php';
require_once __DIR__ . '/utils/class-cb-mail.php';
require_once __DIR__ . '/modules/class-cb-plugin.php';
require_once __DIR__ . '/modules/class-cb-shortcodes.php';
require_once __DIR__ . '/modules/class-cb-admin.php';
require_once __DIR__ . '/modules/class-cb-admin-ajax.php';
require_once __DIR__ . '/modules/class-cb-admin-rest.php';
require_once __DIR__ . '/modules/class-cb-dashboard.php';
require_once __DIR__ . '/modules/class-cb-account-dashboard.php';
require_once __DIR__ . '/modules/class-cb-dashboard-rest.php';
require_once __DIR__ . '/modules/class-cb-maintenance.php';
require_once __DIR__ . '/modules/class-cb-scheduled-events.php';
require_once __DIR__ . '/modules/class-cb-audit-log.php';
require_once __DIR__ . '/modules/class-cb-api.php';
require_once __DIR__ . '/modules/class-cb-api-proxy.php';
require_once __DIR__ . '/modules/class-cb-wc-sync.php';
require_once __DIR__ . '/modules/class-cb-webhooks.php';
require_once __DIR__ . '/modules/class-cb-frontend.php';
require_once __DIR__ . '/modules/class-cb-frontend-rest.php';
require_once __DIR__ . '/modules/class-cb-checkout.php';
require_once __DIR__ . '/modules/class-cb-debug.php';


/**
 * Register 5-minute cron schedule.
 */
add_filter('cron_schedules', function ($schedules) {
    // Calendly Bookings custom intervals (≤ 24h)
    $schedules['cb_every_5_minutes'] = [
        'interval' => 300,
        'display'  => __('Every 5 Minutes', 'calendly-bookings'),
    ];
    $schedules['cb_every_15_minutes'] = [
        'interval' => 900,
        'display'  => __('Every 15 Minutes', 'calendly-bookings'),
    ];
    $schedules['cb_every_30_minutes'] = [
        'interval' => 1800,
        'display'  => __('Every 30 Minutes', 'calendly-bookings'),
    ];
    $schedules['cb_hourly'] = [
        'interval' => 3600,
        'display'  => __('Hourly', 'calendly-bookings'),
    ];
    $schedules['cb_twicedaily'] = [
        'interval' => 43200,
        'display'  => __('Twice Daily', 'calendly-bookings'),
    ];
    $schedules['cb_daily'] = [
        'interval' => 86400,
        'display'  => __('Daily', 'calendly-bookings'),
    ];

    return $schedules;
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
    update_option('cb_last_sync_all', current_time('mysql'));
});

add_action('plugins_loaded', function () {
    // Run migrations if needed.
    CB_Installer::maybe_run();

    Modules\CB_Plugin::init();
    Modules\CB_Shortcodes::init();
    Modules\CB_Webhooks::init();
    Modules\CB_Dashboard::init();
    Modules\CB_Dashboard_REST::init();
    Modules\CB_WC_Sync::init();
    Modules\CB_API::init();
    Modules\CB_API_Proxy::init();
    Modules\CB_Frontend::init();
    Modules\CB_Frontend_Rest::init();
    Modules\CB_Account_Dashboard::init();
    Modules\CB_Admin::init();
    Modules\CB_Admin_Ajax::init();
    Modules\CB_Admin_Rest::init();
    Modules\CB_Maintenance::init();
    Modules\CB_Scheduled_Events::init();
    Modules\CB_Checkout::register();
    Modules\CB_Debug::init();
    Utils\CB_Encryption::init();

});
