<?php
// includes/bootstrap.php
namespace Calendly_Bookings;

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/installer.php';
register_activation_hook( __FILE__, [ 'CB_Installer', 'init' ] );

require_once __DIR__ . '/modules/class-cb-plugin.php';
require_once __DIR__ . '/utils/functions.php';
require_once __DIR__ . '/utils/class-cb-timezone-converter.php';
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

add_action('plugins_loaded', function() {
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
});
