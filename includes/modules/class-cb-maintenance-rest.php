<?php
namespace Calendly_Bookings\Modules;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class CB_Maintenance_REST {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        $ns = 'calendly-bookings/v1';

        // Clear cache
        register_rest_route($ns, '/maintenance/clear-cache', [
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'clear_cache'],
            'args'                => [
                'scope' => [
                    'type'    => 'string',
                    'enum'    => ['all', 'event_types', 'availability', 'events'],
                    'default' => 'all',
                ],
            ],
        ]);

        // Rebuild product links
        register_rest_route($ns, '/maintenance/rebuild-links', [
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'rebuild_links'],
        ]);

        // Update created_ts
        register_rest_route($ns, '/maintenance/update-created-ts', [
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'update_created_ts'],
        ]);

        // Refresh URLs
        register_rest_route($ns, '/maintenance/refresh-urls', [
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'refresh_urls'],
        ]);

        // Backfill Order IDs
        register_rest_route($ns, '/maintenance/backfill-order-ids', [
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'backfill_order_ids'],
        ]);

        // Normalize statuses
        register_rest_route($ns, '/maintenance/normalize-statuses', [
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'normalize_statuses'],
        ]);
    }

    /** Permissions */
    public static function can_manage(): bool {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    /** REST callbacks (delegate to CB_Maintenance model) */
    public static function clear_cache(WP_REST_Request $r): WP_REST_Response {
        $scope   = $r->get_param('scope') ?: 'all';
        $deleted = CB_Maintenance::instance()->clear_api_cache();
        return $deleted;
    }

    public static function rebuild_links(WP_REST_Request $r): WP_REST_Response {
        return CB_Maintenance::instance()->rebuild_product_links();
    }

    public static function update_created_ts(WP_REST_Request $r): WP_REST_Response {
        CB_Maintenance::instance()->update_created_ts();
        return new WP_REST_Response(['success' => true, 'message' => 'Updated created_ts'], 200);
    }

    public static function refresh_urls(WP_REST_Request $r): WP_REST_Response {
        CB_Maintenance::instance()->refresh_urls();
        return new WP_REST_Response(['success' => true, 'message' => 'Refreshed URLs'], 200);
    }

    public static function backfill_order_ids(WP_REST_Request $r): WP_REST_Response {
        CB_Maintenance::instance()->backfill_order_ids();
        return new WP_REST_Response(['success' => true, 'message' => 'Backfilled Order IDs'], 200);
    }

    public static function normalize_statuses(WP_REST_Request $r): WP_REST_Response {
        CB_Maintenance::instance()->normalize_statuses();
        return new WP_REST_Response(['success' => true, 'message' => 'Normalized statuses'], 200);
    }
}
