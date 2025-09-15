<?php
namespace Calendly_Bookings\Modules;
use Calendly_Bookings\CB_Constants;
use WC_Order_Query;

if (!defined('ABSPATH')) exit;

final class CB_REST_Dashboard {
    public static function init(): void {
        add_action('rest_api_init', function() {
            $ns = 'calendly-bookings/v1';
            register_rest_route($ns, '/summary', [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'get_summary'],
                'permission_callback' => fn() => current_user_can('manage_options')
            ]);
            register_rest_route($ns, '/trends', [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'get_trends'],
                'permission_callback' => fn() => current_user_can('manage_options')
            ]);
            register_rest_route($ns, '/availability', [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'get_availability'],
                'permission_callback' => fn() => current_user_can('manage_options')
            ]);
            register_rest_route($ns, '/integrity', [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'get_integrity'],
                'permission_callback' => fn() => current_user_can('manage_options')
            ]);
            register_rest_route($ns, '/upcoming-events', [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'get_scheduled_events'],
                'permission_callback' => fn() => current_user_can('manage_options')
            ]);
        });
    }

    /** SUMMARY: today, upcoming, revenue, health */
    public static function get_summary(): array {
        global $wpdb;
        $today_start = gmdate('Y-m-d 00:00:00');
        $today_end   = gmdate('Y-m-d 23:59:59');

        // Today’s bookings
        $today = $wpdb->get_results($wpdb->prepare(
            "SELECT name, start_time AS `when`, status
             FROM {$wpdb->prefix}cb_scheduled_events
             WHERE start_time BETWEEN %s AND %s
             ORDER BY start_time ASC",
            $today_start, $today_end
        ), ARRAY_A);

        // Upcoming meetings (next 7 days)
        $upcoming = $wpdb->get_results($wpdb->prepare(
            "SELECT name, start_time AS `when`, status
             FROM {$wpdb->prefix}cb_scheduled_events
             WHERE start_time > %s
             ORDER BY start_time ASC
             LIMIT 5",
            $today_end
        ), ARRAY_A);

        // Revenue this month
        $month_start = gmdate('Y-m-01 00:00:00');
$orders = wc_get_orders([
    'limit'        => -1,
    'status'       => ['wc-completed', 'wc-processing'],
    'date_created' => $month_start . '...' . $today_end,
    'return'       => 'ids',
    'meta_query'   => [
        [
            'key'     => '_cb_event_uuid',
            'compare' => 'EXISTS'
        ]
    ]
]);
$this_month_total = array_sum(array_map(fn($id) => (float) wc_get_order($id)->get_total(), $orders));

        // Revenue last month for MoM %
        $last_month_start = gmdate('Y-m-01 00:00:00', strtotime('-1 month'));
        $last_month_end   = gmdate('Y-m-t 23:59:59', strtotime('-1 month'));
$last_orders = wc_get_orders([
    'limit'        => -1,
    'status'       => ['wc-completed', 'wc-processing'],
    'date_created' => $last_month_start . '...' . $last_month_end,
    'return'       => 'ids',
    'meta_query'   => [
        [
            'key'     => '_cb_event_uuid',
            'compare' => 'EXISTS'
        ]
    ]
]);
$last_month_total = array_sum(array_map(fn($id) => (float) wc_get_order($id)->get_total(), $last_orders));
		
$mom = $last_month_total > 0
    ? sprintf('%+0.1f%%', (($this_month_total - $last_month_total) / $last_month_total) * 100)
    : '—';
        // Health
        $last_sync = get_option(CB_Constants::OPT_LAST_SYNC) ?: null;
        $errors24h = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}cb_audit_log WHERE type='error' AND created_at >= %s",
            gmdate('Y-m-d H:i:s', strtotime('-24 hours'))
        ));

        return [
            'today'    => $today,
            'upcoming' => $upcoming,
            'revenue'  => [
                'this_month' => (float)$this_month_total,
                'mom'        => $mom
            ],
            'health'   => [
                'api'       => 'OK', // Could be dynamic from API health check
                'last_sync' => $last_sync,
                'errors24h' => $errors24h
            ]
        ];
    }

    /** TRENDS: bookings per day */
    public static function get_trends(\WP_REST_Request $req): array {
        global $wpdb;
        $days = absint($req->get_param('days') ?: 30);
        $labels = [];
        $counts = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = gmdate('Y-m-d', strtotime("-$i days"));
            $labels[] = gmdate('M j', strtotime($date));
            $counts[] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cb_scheduled_events WHERE DATE(start_time) = %s",
                $date
            ));
        }
        return ['labels' => $labels, 'counts' => $counts];
    }

    /** AVAILABILITY: next slot per event type */
    public static function get_availability(): array {
        global $wpdb;
        $rows = $wpdb->get_results("
            SELECT et.uuid, et.name, et.duration, MIN(se.start_time) AS next_slot
            FROM {$wpdb->prefix}cb_event_types et
            LEFT JOIN {$wpdb->prefix}cb_scheduled_events se ON se.event_uuid = et.uuid AND se.start_time >= NOW()
            GROUP BY et.uuid
            ORDER BY et.name ASC
        ");
        return array_map(fn($r) => [
            'url'      => admin_url('post.php?post=' . $r->uuid . '&action=edit'),
            'name'     => $r->name,
            'uuid'     => $r->uuid,
            'next_slot'=> $r->next_slot,
            'price'    => '', // Could pull from linked WC product
            'duration' => $r->duration
        ], $rows);
    }

    /** INTEGRITY: duplicates and missing UUIDs */
    public static function get_integrity(): array {
        global $wpdb;
        $duplicates = [];
        $dupe_rows = $wpdb->get_results("
            SELECT uuid, GROUP_CONCAT(product_id) AS products
            FROM {$wpdb->prefix}cb_event_types
            WHERE product_id IS NOT NULL
            GROUP BY uuid
            HAVING COUNT(product_id) > 1
        ");
        foreach ($dupe_rows as $row) {
            $products = [];
            foreach (explode(',', $row->products) as $pid) {
                $products[] = [
                    'url'        => get_edit_post_link($pid),
                    'name'       => get_the_title($pid),
                    'product_id' => (int) $pid
                ];
            }
            $duplicates[] = ['uuid' => $row->uuid, 'products' => $products];
        }

        $missing = [];
        $missing_rows = $wpdb->get_results("
            SELECT ID, post_title
            FROM {$wpdb->posts}
            WHERE post_type='product'
              AND post_status='publish'
              AND ID NOT IN (SELECT product_id FROM {$wpdb->prefix}cb_event_types WHERE product_id IS NOT NULL)
        ");
        foreach ($missing_rows as $m) {
            $missing[] = [
                'url'        => get_edit_post_link($m->ID),
                'name'       => $m->post_title,
                'product_id' => $m->ID
            ];
        }

        return ['duplicates' => $duplicates, 'missing' => $missing];
    }

    /** SCHEDULED EVENTS: upcoming meetings */
    public static function get_scheduled_events(\WP_REST_Request $req): array {
        global $wpdb;
        $limit = absint($req->get_param('limit') ?: 5);
        return $wpdb->get_results($wpdb->prepare(
            "SELECT name, start_time AS `when`, status
             FROM {$wpdb->prefix}cb_scheduled_events
             WHERE start_time >= NOW()
             ORDER BY start_time ASC
             LIMIT %d",
            $limit
        ), ARRAY_A);
    }
}
