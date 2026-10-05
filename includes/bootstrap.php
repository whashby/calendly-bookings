<?php
declare(strict_types=1);

namespace Calendly_Bookings;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/installer.php';
require_once __DIR__ . '/utils/functions.php';
require_once __DIR__ . '/utils/class-cb-timezone-converter.php';
require_once __DIR__ . '/utils/class-cb-encryption.php';
require_once __DIR__ . '/utils/class-cb-mail.php';

require_once __DIR__ . '/modules/class-cb-plugin.php';
require_once __DIR__ . '/modules/class-cb-sync-status.php';
require_once __DIR__ . '/modules/class-cb-api.php';
require_once __DIR__ . '/modules/class-cb-webhooks.php';
require_once __DIR__ . '/modules/class-cb-booking-reconciliation.php';
require_once __DIR__ . '/modules/class-cb-action-scheduler.php';
require_once __DIR__ . '/modules/class-cb-email.php';
require_once __DIR__ . '/modules/class-cb-reports.php';
require_once __DIR__ . '/modules/class-cb-scheduled-events.php';
require_once __DIR__ . '/modules/class-cb-dashboard-rest.php';
require_once __DIR__ . '/modules/class-cb-api-proxy.php';
require_once __DIR__ . '/modules/class-cb-frontend-rest.php';
require_once __DIR__ . '/modules/class-cb-admin-rest.php';

/**
 * Register schedules once. No third-party assets or database work is performed here.
 */
add_filter('cron_schedules', static function (array $schedules): array {
    $schedules['cb_every_5_minutes'] = ['interval' => 300, 'display' => __('Every 5 Minutes', 'calendly-bookings')];
    $schedules['cb_every_15_minutes'] = ['interval' => 900, 'display' => __('Every 15 Minutes', 'calendly-bookings')];
    $schedules['cb_every_30_minutes'] = ['interval' => 1800, 'display' => __('Every 30 Minutes', 'calendly-bookings')];
    $schedules['cb_hourly'] = ['interval' => 3600, 'display' => __('Hourly', 'calendly-bookings')];
    $schedules['cb_twicedaily'] = ['interval' => 43200, 'display' => __('Twice Daily', 'calendly-bookings')];
    $schedules['cb_daily'] = ['interval' => 86400, 'display' => __('Daily', 'calendly-bookings')];
    return $schedules;
});

register_deactivation_hook(dirname(__DIR__) . '/calendly-bookings.php', static function (): void {
    CB_Installer::deactivate();
});

add_action('cb_sync_master_cron', static function (): void {
    $api = Modules\CB_API::instance();
$api->sync(get_option(CB_Constants::OPT_MIN_START_DATE) ?: null, true);
});

add_action('cb_sync_scheduled_events_cron', static function (): void {
    Modules\CB_API::instance()->sync_scheduled_events(null, get_option(CB_Constants::OPT_MIN_START_DATE) ?: null);
});
add_action('cb_sync_invitees_cron', static function (): void {
    Modules\CB_API::instance()->sync_scheduled_event_invitees();
});
add_action('cb_sync_event_types_cron', static function (): void {
    Modules\CB_API::instance()->sync_event_types();
});
add_action('cb_sync_locations_cron', static function (): void {
    Modules\CB_API::instance()->sync_locations();
});

add_action('plugins_loaded', static function (): void {
    CB_Installer::maybe_run();

    // Core services required for webhook, queue, API and report workers.
    Modules\CB_Plugin::init();
    Modules\CB_API::init();
    Modules\CB_Webhooks::init();
    Modules\CB_Booking_Reconciliation::init();
    Modules\CB_Action_Scheduler::init();
    Modules\CB_Email::init();
    Modules\CB_Reports::init();
    Modules\CB_Scheduled_Events::init();

    if (class_exists('WooCommerce')) {
        require_once __DIR__ . '/modules/class-cb-checkout.php';
        Modules\CB_Checkout::register();
    }

    if (is_admin()) {
        require_once __DIR__ . '/modules/class-cb-admin.php';
        require_once __DIR__ . '/modules/class-cb-admin-ajax.php';
        require_once __DIR__ . '/modules/class-cb-dashboard.php';
        require_once __DIR__ . '/modules/class-cb-maintenance.php';
        require_once __DIR__ . '/modules/class-cb-wc-sync.php';

        Modules\CB_Admin::init();
        Modules\CB_Admin_Ajax::init();
        Modules\CB_Dashboard::init();
        Modules\CB_Maintenance::init();
    }

    // Front-end modules are not loaded on wp-admin requests.
    if (!is_admin()) {
        require_once __DIR__ . '/modules/class-cb-shortcodes.php';
        require_once __DIR__ . '/modules/class-cb-frontend.php';
        require_once __DIR__ . '/modules/class-cb-account-dashboard.php';

        Modules\CB_Shortcodes::init();
        Modules\CB_Frontend::init();
        Modules\CB_Account_Dashboard::init();
    }

    // REST route classes must be registered during plugins_loaded so their
    // rest_api_init callbacks exist before WordPress dispatches a REST request.
    // REST_REQUEST is not a reliable gate at plugins_loaded.
    Modules\CB_API_Proxy::init();
    Modules\CB_Frontend_Rest::init();
    Modules\CB_Dashboard_REST::init();
    Modules\CB_Admin_Rest::init();

    if (defined('WP_DEBUG') && WP_DEBUG) {
        require_once __DIR__ . '/modules/class-cb-debug.php';
        Modules\CB_Debug::init();
    }

    Utils\CB_Encryption::init();
});
