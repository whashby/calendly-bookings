<?php
// includes/bootstrap.php
namespace Calendly_Bookings;

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/installer.php';
require_once __DIR__ . '/utils/functions.php';
require_once __DIR__ . '/modules/class-cb-plugin.php';
require_once __DIR__ . '/modules/class-cb-dashboard.php';
require_once __DIR__ . '/modules/class-cb-rest-dashboard.php';
//require_once __DIR__ . '/modules/class-cb-rest-availability.php';
require_once __DIR__ . '/modules/class-cb-logger.php';
require_once __DIR__ . '/modules/class-cb-api.php';
require_once __DIR__ . '/modules/class-cb-wc-sync.php';
require_once __DIR__ . '/modules/class-cb-webhooks.php';
require_once __DIR__ . '/modules/class-cb-api-proxy.php';
require_once __DIR__ . '/modules/class-cb-frontend.php';
require_once __DIR__ . '/modules/class-cb-admin.php';
//require_once __DIR__ . '/modules/class-cb-maintenance.php';

add_action('plugins_loaded', function() {
    CB_Installer::init();
    Modules\CB_Plugin::init();
    Modules\CB_Dashboard::init();
    Modules\CB_REST_Dashboard::init();
//    Modules\CB_REST_Availability::init();
    Modules\CB_Logger::init();
    Modules\CB_WC_Sync::init();
    Modules\CB_Webhooks::init();
    Modules\CB_API_Proxy::init();
    Modules\CB_Frontend::init();
    Modules\CB_Admin::init();
//    Modules\CB_Maintenance::init();
});
