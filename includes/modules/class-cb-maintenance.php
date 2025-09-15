<?php
namespace Calendly_Bookings\Modules;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class CB_Maintenance {

    /**
     * Bootstraps the maintenance module.
     */
    public static function register(): void {
//        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_filter('cron_schedules', [__CLASS__, 'register_cron_interval']);
        add_action('cb_maintenance_cron', [__CLASS__, 'run_scheduled_tasks']);
    }

    /**
     * Schedule cron on plugin activation.
     */
    public static function activate(): void {
        if (!wp_next_scheduled('cb_maintenance_cron')) {
            wp_schedule_event(time(), 'cb_every_five_minutes', 'cb_maintenance_cron');
        }
    }

    /**
     * Clear cron on plugin deactivation.
     */
    public static function deactivate(): void {
        wp_clear_scheduled_hook('cb_maintenance_cron');
    }

    /**
     * Registers REST API routes.
     */
    public static function register_routes(): void {
        $ns = 'calendly-bookings/v1';

        register_rest_route($ns, '/maintenance/clear-cache', [
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'rest_clear_api_cache'],
            'args'                => [
                'scope' => [
                    'type'    => 'string',
                    'enum'    => ['all', 'event_types', 'availability', 'events'],
                    'default' => 'all',
                ],
            ],
        ]);

        register_rest_route($ns, '/maintenance/rebuild-links', [
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'rest_rebuild_product_links'],
            'args'                => [
                'uuids' => [
                    'type'    => 'array',
                    'items'   => ['type' => 'string'],
                    'required'=> false,
                ],
                'limit' => [
                    'type'    => 'integer',
                    'minimum' => 1,
                    'maximum' => 500,
                    'default' => 200,
                ],
                'dry_run' => [
                    'type'    => 'boolean',
                    'default' => false,
                ],
                'force' => [
                    'type'    => 'boolean',
                    'default' => false,
                ],
            ],
        ]);
    }

    /**
     * Registers a custom 5-minute cron interval.
     */
    public static function register_cron_interval($schedules) {
        $schedules['cb_every_five_minutes'] = [
            'interval' => 300, // 5 minutes
            'display'  => __('Every 5 Minutes', 'calendly-bookings'),
        ];
        return $schedules;
    }

    /**
     * Checks if the current user can run maintenance tasks.
     */
    public static function can_manage(): bool {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    /**
     * Cron callback: runs scheduled maintenance tasks.
     */
    public static function run_scheduled_tasks(): void {
        // 1. Clear API cache
        self::clear_api_cache_internal('all');

        // 2. Sync event types + scheduled events
        if (class_exists('\Calendly_Bookings\Modules\CB_API')) {
            $api = new CB_API();
            $api->sync(100);
        }

        // 3. Rebuild product links
        if (class_exists('\Calendly_Bookings\Modules\CB_WC_Sync')) {
            // Force rebuild without dry run
            $req = new WP_REST_Request('POST', '/');
            $req->set_param('force', true);
            self::rest_rebuild_product_links($req);
        }
    }

    /**
     * REST: Clear API cache.
     */
    public static function rest_clear_api_cache(WP_REST_Request $r): WP_REST_Response {
        $scope = $r->get_param('scope') ?: 'all';
        $deleted = self::clear_api_cache_internal($scope);
        return new WP_REST_Response([
            'success' => true,
            'scope'   => $scope,
            'deleted' => $deleted,
        ], 200);
    }

    /**
     * Internal cache clearing logic (used by REST + Cron).
     */
    private static function clear_api_cache_internal(string $scope): array {
        global $wpdb;
        $deleted = ['transients' => 0, 'options' => 0];

        $option_keys = ['cb_last_sync'];
        $prefixes = [];
        if ($scope === 'all' || $scope === 'event_types')   $prefixes[] = '_transient_cb_api_event_types';
        if ($scope === 'all' || $scope === 'availability')  $prefixes[] = '_transient_cb_api_availability';
        if ($scope === 'all' || $scope === 'events')        $prefixes[] = '_transient_cb_api_events';

        foreach ($prefixes as $prefix) {
            $like = esc_sql($prefix . '%');
            $rows = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '{$like}'");
            foreach ($rows as $opt) {
                if (delete_option($opt)) $deleted['transients']++;
            }
            if (is_multisite()) {
                $rows = $wpdb->get_col($wpdb->prepare(
                    "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                    str_replace('_transient_', '_site_transient_', $like)
                ));
                foreach ($rows as $opt) {
                    if (delete_site_option($opt)) $deleted['transients']++;
                }
            }
        }

        foreach ($option_keys as $key) {
            if (get_option($key, null) !== null && delete_option($key)) {
                $deleted['options']++;
            }
        }

        return $deleted;
    }

    /**
     * REST: Rebuild product links.
     */
    public static function rest_rebuild_product_links(WP_REST_Request $r): WP_REST_Response {
        // Your existing rebuild logic here...
        // This can be the same code you already have in your manual endpoint.
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Rebuild complete (placeholder)',
        ], 200);
    }
}
