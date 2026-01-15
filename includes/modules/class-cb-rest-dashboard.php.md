# Copy of class-cb-rest-dashboard.php

```php
<?php
namespace Calendly_Bookings\Modules;

use Calendly_Bookings\CB_Constants;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class CB_REST_Dashboard {
    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

	public static function register_routes(): void {
		register_rest_route('cb/v1', '/dashboard/availability', [
			'methods'  => 'GET',
			'callback' => [__CLASS__, 'get_availability_snapshot'],
			'permission_callback' => fn() => current_user_can('manage_options'),
		]);

		register_rest_route('cb/v1', '/dashboard/integrity', [
			'methods'  => 'GET',
			'callback' => [__CLASS__, 'get_data_integrity'],
			'permission_callback' => fn() => current_user_can('manage_options'),
		]);

		register_rest_route('cb/v1', '/dashboard/revenue', [
			'methods'  => 'GET',
			'callback' => [self::class, 'get_revenue_tracker'],
			'permission_callback' => fn() => current_user_can('manage_options'),
			'args' => [
				'months' => [
					'type' => 'integer',
					'default' => 1,
					'sanitize_callback' => 'absint',
				],
			],
		]);


		register_rest_route('cb/v1', '/dashboard/health', [
			'methods'  => 'GET',
			'callback' => [__CLASS__, 'get_sync_health'],
			'permission_callback' => fn() => current_user_can('manage_options'),
		]);

		register_rest_route('cb/v1', '/dashboard/sync', [
			'methods'  => 'GET',
			'callback' => [__CLASS__, 'sync_health'],
			'permission_callback' => fn() => current_user_can('manage_options'),
		]);

		register_rest_route('cb/v1', '/dashboard/trends', [
			'methods'  => 'GET',
			'callback' => [self::class, 'get_booking_trends'],
			'permission_callback' => fn() => current_user_can('manage_options'),
			'args' => [
				'months' => [
					'type' => 'integer',
					'default' => 1,
					'sanitize_callback' => 'absint',
				],
			],
		]);

		register_rest_route('cb/v1', '/dashboard/fix-missing-uuid', [ 
			'methods' => 'POST', 
			'callback' => [self::class, 'fix_missing_uuid'], 
			'permission_callback' => fn() => current_user_can('manage_options'), 
			'args' => [ 
				'id' => [
					'type' => 'integer', 
					'required' => true
				], 
			], 
		]); 
		
		register_rest_route('cb/v1', '/dashboard/fix-duplicate', [ 
			'methods' => 'POST', 
			'callback' => [self::class, 'fix_duplicate'], 
			'permission_callback' => fn() => current_user_can('manage_options'), 
			'args' => [ 
				'uuid' => [
					'type' => 'string', 
					'required' => true
				], 
			], 
		]);

		register_rest_route('cb/v1', '/dashboard/performance', [
			'methods'  => 'GET',
			'callback' => [self::class, 'get_event_type_performance'],
			'permission_callback' => fn() => current_user_can('manage_options'),
			'args' => [
				'months' => [
					'type' => 'integer',
					'default' => 1,
					'sanitize_callback' => 'absint',
				],
			],
		]);

		register_rest_route('cb/v1', '/dashboard/recent-bookings', [
			'methods'  => 'GET',
			'callback' => [self::class, 'get_recent_bookings'],
			'permission_callback' => fn() => current_user_can('manage_options'),
		]);
	}

	public static function get_availability_snapshot(): array {
		global $wpdb;
		$table_event_types  = $wpdb->prefix . 'cb_event_types';
		$table_times        = $wpdb->prefix . 'cb_event_type_available_times';

		// Current time + 30 minutes
		$cutoff = strtotime('+30 minutes');

		$event_types = $wpdb->get_results("SELECT id, name FROM {$table_event_types} WHERE active = 1", ARRAY_A);
		$results = [];

		foreach ($event_types as $et) {
			// Get all slots for this event type
			$slots = $wpdb->get_col($wpdb->prepare(
				"SELECT start_time FROM {$table_times}
				 WHERE event_type_id = %d
				 ORDER BY start_time ASC",
				$et['id']
			));

			if (!$slots) {
				$results[] = ['name' => $et['name'], 'slots' => []];
				continue;
			}

			// Normalize to timestamps
			$timestamps = array_map(fn($s) => strtotime($s), $slots);

			// Find closest slot >= cutoff
			$closest = null;
			foreach ($timestamps as $ts) {
				if ($ts >= $cutoff) {
					$closest = $ts;
					break;
				}
			}

			// Round closest to next half hour or hour
			if ($closest) {
				$minutes = (int) date('i', $closest);
				$rounded = $closest;
				if ($minutes <= 30) {
					// round up to half hour
					$rounded = strtotime(date('Y-m-d H:30:00', $closest));
					if ($rounded < $closest) {
						$rounded = strtotime(date('Y-m-d H:00:00', $closest) . ' +1 hour');
					}
				} else {
					// round up to next hour
					$rounded = strtotime(date('Y-m-d H:00:00', $closest) . ' +1 hour');
				}
				$closest = $rounded;
			}

			// Farthest slot = last stored time
			$farthest = end($timestamps);

			$results[] = [
				'name'  => $et['name'],
				'slots' => array_filter([
					$closest ? gmdate('Y-m-d H:i', $closest) : null,
				]),
			];
		}

		return $results;
	}

	public static function get_data_integrity(): array {
		global $wpdb;
		$table_events = $wpdb->prefix . 'cb_scheduled_events';

		// Missing UUIDs
		$missing_uuid = $wpdb->get_results("
			SELECT id, name, start_time
			FROM {$table_events}
			WHERE (uuid IS NULL OR uuid = '')
			ORDER BY start_time ASC
			LIMIT 10
		", ARRAY_A);

		// Duplicates
		$duplicates = $wpdb->get_results("
			SELECT uuid, COUNT(*) as count
			FROM {$table_events}
			WHERE uuid IS NOT NULL AND uuid <> ''
			GROUP BY uuid
			HAVING COUNT(*) > 1
			ORDER BY count DESC
			LIMIT 10
		", ARRAY_A);

		// Convert times to site timezone
		$tz = wp_timezone();
		foreach ($missing_uuid as &$row) {
			if (!empty($row['start_time'])) {
				$row['start_time'] = wp_date('Y-m-d H:i', strtotime($row['start_time']), $tz);
			}
		}

		return [
			'missing_uuid' => $missing_uuid,
			'duplicates'   => $duplicates,
		];
	}


	public static function fix_missing_uuid(\WP_REST_Request $request): array { 
		global $wpdb; 
		$id = (int) $request->get_param('id'); 
		$uuid = wp_generate_uuid4(); 
		$wpdb->update( $wpdb->prefix . 'cb_scheduled_events', ['uuid' => $uuid], ['id' => $id], ['%s'], ['%d'] ); 
		
		return ['status' => 'success', 
				'message' => "UUID fixed for event #{$id}", 
				'uuid' => $uuid
			   ]; 
	} 
	
	public static function fix_duplicate(\WP_REST_Request $request): array { 
		global $wpdb; 
		$uuid = $request->get_param('uuid'); // Strategy: keep the first record, reassign new UUIDs to duplicates 
		$rows = $wpdb->get_results($wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cb_scheduled_events WHERE uuid = %s ORDER BY id ASC", $uuid ), ARRAY_A); 
		$keep = array_shift($rows); 
		foreach ($rows as $row) { 
			$wpdb->update( $wpdb->prefix . 'cb_scheduled_events', 
						  ['uuid' => wp_generate_uuid4()], 
						  ['id' => $row['id']], 
						  ['%s'], 
						  ['%d'] 
						 ); 
		} 
		
		return [
			'status' => 'success', 
			'message' => "Duplicates fixed for UUID {$uuid}"
		]; 
	}

	public static function get_revenue_tracker(\WP_REST_Request $request): array {
		$months = (int) $request->get_param('months') ?: 1;

		// Site timezone
		$tz = wp_timezone();

		// Date range
		$start = null;
		$end   = wp_date('Y-m-d H:i:s', null, $tz);

		if ($months > 0) {
			$start = wp_date('Y-m-d H:i:s', strtotime("-{$months} months"), $tz);
		}

		// Helper: sum WooCommerce order totals
		$sum_orders = function($start, $end) {
			$args = [
				'status'       => ['completed'],
				'limit'        => -1,
				'return'       => 'ids',
			];
			if ($start) {
				$args['date_created'] = $start . '...' . $end;
			}
			$orders = wc_get_orders($args);
			$total = 0;
			foreach ($orders as $order_id) {
				$order = wc_get_order($order_id);
				$total += $order->get_total();
			}
			return $total;
		};

		$revenue = $sum_orders($start, $end);

		// Top event types by revenue
		global $wpdb;
		$table_event_types = $wpdb->prefix . 'cb_event_types';
		$rows = $wpdb->get_results("
			SELECT et.id, et.name, et.product_id
			FROM {$table_event_types} et
			WHERE et.active = 1
		", ARRAY_A);

		$top = [];
		foreach ($rows as $et) {
			if ($et['product_id']) {
				$product = wc_get_product((int) $et['product_id']);
				if ($product) {
					$orders = wc_get_orders([
						'status' => ['active'],
						'limit'  => -1,
						'date_created' => $start ? $start . '...' . $end : null,
					]);
					$revenue_et = 0;
					foreach ($orders as $order) {
						foreach ($order->get_items() as $item) {
							if ($item->get_product_id() == $et['product_id']) {
								$revenue_et += $item->get_total();
							}
						}
					}
					$top[] = ['name' => $et['name'], 'revenue' => $revenue_et];
				}
			}
		}

		usort($top, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

		return [
			'months'     => $months,
			'revenue'    => $revenue,
			'top_events' => $top,
		];
	}

	public static function get_sync_health(): array {
		global $wpdb;
		$last_sync = get_option(CB_Constants::OPT_LAST_SYNC) ?: 'Never';
		$errors24h = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cb_audit_log
			 WHERE level = 'error' AND timestamp >= %s",
			gmdate('Y-m-d H:i:s', strtotime('-24 hours'))
		));

		return [
			'calendly_api' => 'OK', // placeholder
			'last_sync'    => $last_sync,
			'errors24h'    => $errors24h,
		];
	}

	public static function sync_health(): array {
		try {
			$api = new CB_API();
			$result = $api->sync();

			update_option(CB_Constants::OPT_LAST_SYNC, gmdate('Y-m-d H:i:s'));

			return [
				'status' 	=> 'success',
				'message'   => 'Sync completed',
				'last_sync' => get_option(CB_Constants::OPT_LAST_SYNC),
				'details'	=> $result,
			];
		} catch (\Exception $e) { 
			return [ 
				'status' => 'error', 
				'message' => 'Sync failed: ' . $e->getMessage(), 
			]; 
		}
	}

	public static function get_booking_trends(\WP_REST_Request $request): array {
		global $wpdb;
		$table_events = $wpdb->prefix . 'cb_scheduled_events';

		// Default range = 1 month
		$months = (int) $request->get_param('months') ?: 1;

		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT DATE(start_time) as day, COUNT(*) as count
			 FROM {$table_events}
			 WHERE status = 'active'
			   AND start_time >= DATE_SUB(NOW(), INTERVAL %d MONTH)
			 GROUP BY day
			 ORDER BY day ASC",
			$months
		), ARRAY_A);

		return array_map(fn($row) => [
			'day'   => $row['day'],
			'count' => (int) $row['count'],
		], $rows);
	}

	public static function get_event_type_performance(\WP_REST_Request $request): array {
		global $wpdb;
		$table_event_types = $wpdb->prefix . 'cb_event_types';
		$table_events      = $wpdb->prefix . 'cb_scheduled_events';

		$months = (int) $request->get_param('months') ?: 1;

		$where = "se.status='active'";
		if ($months > 0) {
			$where .= $wpdb->prepare(" AND se.start_time >= DATE_SUB(NOW(), INTERVAL %d MONTH)", $months);
		}

		$rows = $wpdb->get_results("
			SELECT et.id, et.name, et.product_id,
				   COUNT(se.id) as bookings
			FROM {$table_event_types} et
			LEFT JOIN {$table_events} se ON se.event_type_id = et.id AND {$where}
			GROUP BY et.id, et.name, et.product_id
			ORDER BY id DESC
		", ARRAY_A);

		$results = [];
		foreach ($rows as $r) {
			$product_price = 0;
			if (!empty($r['product_id'])) {
				$product = wc_get_product((int) $r['product_id']);
				if ($product) {
					$product_price = (float) $product->get_price();
				}
			}

        $results[] = [
            'id'       => (int) $r['id'],
            'name'     => $r['name'],
            'bookings' => (int) $r['bookings'],
            'revenue'  => $product_price * (int) $r['bookings'],
        ];
    }

    return $results;
}
	
	public static function get_recent_bookings(): array {
		global $wpdb;
		$table_events = $wpdb->prefix . 'cb_scheduled_events';

		$rows = $wpdb->get_results("
			SELECT id, name, start_time, status
			FROM {$table_events}
			ORDER BY start_time DESC
			LIMIT 10
		", ARRAY_A);

		return array_map(fn($r) => [
			'id'        => (int) $r['id'],
			'name'      => $r['name'],
			'start_time'=> gmdate('Y-m-d H:i', strtotime($r['start_time'])),
			'status'    => $r['status'],
		], $rows);
	}

}
```
