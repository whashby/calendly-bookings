<?php
// includes/bootstrap.php
namespace Calendly_Bookings;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/installer.php';
require_once __DIR__ . '/utils/functions.php';
require_once __DIR__ . '/utils/class-cb-timezone-converter.php';
require_once __DIR__ . '/utils/class-cb-encryption.php';

/**
 * Register 5-minute cron schedule.
 */
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['every_5_minutes'])) {
        $schedules['every_5_minutes'] = [
            'interval' => 300,
            'display'  => __('Every 5 Minutes', 'calendly-bookings'),
        ];
    }
    return $schedules;
});

require_once __DIR__ . '/modules/class-cb-plugin.php';
require_once __DIR__ . '/modules/class-cb-shortcodes.php';
require_once __DIR__ . '/modules/class-cb-dashboard.php';
require_once __DIR__ . '/modules/class-cb-account-dashboard.php';
require_once __DIR__ . '/modules/class-cb-dashboard-rest.php';
require_once __DIR__ . '/modules/class-cb-maintenance.php';
require_once __DIR__ . '/modules/class-cb-scheduled-events.php';
require_once __DIR__ . '/modules/class-cb-logger.php';
require_once __DIR__ . '/modules/class-cb-audit-log.php';
require_once __DIR__ . '/modules/class-cb-api.php';
require_once __DIR__ . '/modules/class-cb-wc-sync.php';
require_once __DIR__ . '/modules/class-cb-webhooks.php';
require_once __DIR__ . '/modules/class-cb-api-proxy.php';
require_once __DIR__ . '/modules/class-cb-frontend.php';
require_once __DIR__ . '/modules/class-cb-frontend-rest.php';
require_once __DIR__ . '/modules/class-cb-admin.php';
require_once __DIR__ . '/modules/class-cb-admin-ajax.php';
require_once __DIR__ . '/modules/class-cb-admin-rest.php';
require_once __DIR__ . '/modules/class-cb-checkout.php';
require_once __DIR__ . '/modules/class-cb-debug.php';

add_action('plugins_loaded', function () {
    // Run migrations if needed.
    CB_Installer::maybe_run();

    Modules\CB_Plugin::init();
    Modules\CB_Shortcodes::init();
    Modules\CB_Webhooks::init();
    Modules\CB_Dashboard::init();
    Modules\CB_Dashboard_REST::init();
    Modules\CB_Logger::init();
    Modules\CB_WC_Sync::init();
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

    /**
     * Cron callback: sync scheduled events every 5 minutes.
     */
    add_action('cb_sync_scheduled_events_cron', function () {
        $last_sync = get_option('cb_last_sync_all', null);

        Modules\CB_API::sync_scheduled_events(null, $last_sync);

        update_option('cb_last_sync_all', current_time('mysql'));
    });
});
