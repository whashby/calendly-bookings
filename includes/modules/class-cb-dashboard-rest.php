<?php

namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;
use Calendly_Bookings\Modules\CB_API;

final class CB_Dashboard_REST {
    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

	public static function register_routes(): void {
	    $ns = 'calendly-bookings/v1';
		register_rest_route($ns, '/dashboard/availability', [
			'methods'  => 'GET',
			'callback' => [__CLASS__, 'get_availability_snapshot'],
			'permission_callback' => fn() => current_user_can('manage_options'),
		]);

		register_rest_route($ns, '/dashboard/integrity', [
			'methods'  => 'GET',
			'callback' => [__CLASS__, 'get_data_integrity'],
			'permission_callback' => fn() => current_user_can('manage_options'),
		]);

		register_rest_route($ns, '/dashboard/revenue', [
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


		register_rest_route($ns, '/dashboard/health', [
			'methods'  => 'GET',
			'callback' => [__CLASS__, 'get_sync_health'],
			'permission_callback' => fn() => current_user_can('manage_options'),
		]);

		register_rest_route($ns, '/dashboard/sync', [
			'methods'  => 'POST',
			'callback' => [__CLASS__, 'sync_data'],
			'permission_callback' => fn() => current_user_can('manage_options'),
		]);

		register_rest_route($ns, '/dashboard/refresh', [
			'methods'  => 'POST',
			'callback' => [__CLASS__, 'refresh_data'],
			'permission_callback' => fn() => current_user_can('manage_options'),
		]);

		register_rest_route($ns, '/dashboard/trends', [
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

		register_rest_route($ns, '/dashboard/fix-missing-uuid', [ 
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
		
		register_rest_route($ns, '/dashboard/fix-duplicate', [ 
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

		register_rest_route($ns, '/dashboard/performance', [
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

		register_rest_route($ns, '/dashboard/recent-bookings', [
			'methods'  => 'GET',
			'callback' => [self::class, 'get_recent_bookings'],
			'permission_callback' => fn() => current_user_can('manage_options'),
		]);
	}

    public static function get_availability_snapshot(): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT et.name, MIN(a.start_time) AS next_slot FROM {$wpdb->prefix}cb_event_types et LEFT JOIN {$wpdb->prefix}cb_event_type_available_times a ON a.event_type_id=et.id AND a.status='available' AND a.invitees_remaining>0 AND a.start_time >= %s WHERE et.active=1 GROUP BY et.id, et.name ORDER BY et.name", gmdate('Y-m-d H:i:s')), ARRAY_A) ?: [];
        return array_map(static fn($row) => ['name' => $row['name'], 'slots' => $row['next_slot'] ? [str_replace(' ', 'T', $row['next_slot']) . 'Z'] : []], $rows);
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
				$row['start_time'] = wp_date('Y-m-d H:i', strtotime($row['start_time'] . ' UTC'), $tz);
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
        $metrics = self::meeting_metrics($request);
        return ['period' => $metrics['months'] . 'M', 'total_revenue' => array_sum(array_column($metrics['events'], 'revenue')), 'events' => $metrics['events'], 'currency' => get_woocommerce_currency(), 'revenue_basis' => 'Recorded order line totals, less refunds, excluding tax; meeting dates in the selected period.', 'excluded_currency_orders' => $metrics['excluded_currency_orders']];
    }

    public static function get_sync_health(): array {
        $states = []; $last_success = 0; $last_attempt = 0; $latest = []; $errors = [];
        foreach (array_merge(array_keys(CB_Sync_Status::HOOKS), ['availability']) as $domain) {
            $state = CB_Sync_Status::state($domain); $states[$domain] = $state;
            $last_success = max($last_success, (int) ($state['last_success'] ?? 0));
            if ((int) ($state['last_attempt'] ?? 0) >= $last_attempt && !empty($state['last_attempt'])) { $last_attempt = (int) $state['last_attempt']; $latest = $state; }
            if (($state['success'] ?? null) === false) $errors[$domain] = $state['errors'] ?? [];
        }
        $configured = (string) get_option(CB_Constants::OPT_API_TOKEN, '') !== '';
        $status = !$configured ? 'Not configured' : ($errors ? 'Sync errors recorded' : ($latest ? 'Last sync succeeded' : 'Not yet verified'));
        return ['calendly_api' => $status, 'last_sync' => CB_Sync_Status::iso($last_success), 'last_attempt' => CB_Sync_Status::iso($last_attempt), 'errors' => $errors, 'schedules' => CB_Sync_Status::schedules(), 'timezone' => wp_timezone()->getName(), 'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, 'availability_last_sync' => CB_Sync_Status::iso($states['availability']['last_success'] ?? 0)];
    }

    public static function sync_data(\WP_REST_Request $request) {
        return self::run_sync(get_option(CB_Constants::OPT_MIN_START_DATE) ?: null);
    }

    public static function refresh_data(\WP_REST_Request $request) {
        return self::run_sync('1970-01-01');
    }

    public static function get_booking_trends(\WP_REST_Request $request): array {
        global $wpdb; [$start, $end] = self::period($request); $days = [];
        $rows = $wpdb->get_col($wpdb->prepare("SELECT start_time FROM {$wpdb->prefix}cb_scheduled_events WHERE status IN ('active','completed') AND start_time BETWEEN %s AND %s", $start, $end)) ?: [];
        foreach ($rows as $time) { $day = wp_date('Y-m-d', strtotime($time . ' UTC'), wp_timezone()); $days[$day] = ($days[$day] ?? 0) + 1; }
        ksort($days); $result = []; foreach ($days as $day => $count) $result[] = ['day' => $day, 'count' => $count];
        return $result;
    }

    public static function get_event_type_performance(\WP_REST_Request $request): array {
        return self::meeting_metrics($request)['events'];
    }

    public static function get_recent_bookings(): array {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT e.id,e.name,e.start_time,e.status,e.order_id,i.invitee FROM {$wpdb->prefix}cb_scheduled_events e LEFT JOIN (SELECT scheduled_event_uuid,GROUP_CONCAT(DISTINCT name ORDER BY name SEPARATOR ', ') AS invitee FROM {$wpdb->prefix}cb_scheduled_event_invitees GROUP BY scheduled_event_uuid) i ON i.scheduled_event_uuid=e.uuid ORDER BY e.created_ts DESC,e.id DESC LIMIT 10", ARRAY_A) ?: [];
        return array_map(static fn($row) => ['id' => (int) $row['id'], 'invitee' => $row['invitee'] ?: 'Invitee not listed', 'event_name' => $row['name'], 'scheduled' => str_replace(' ', 'T', $row['start_time']) . 'Z', 'status' => $row['status']], $rows);
    }

    private static function run_sync(?string $minimum) {
        try {
            $result = CB_API::instance()->sync($minimum, true);
            if (empty($result['success'])) return new \WP_Error('cb_sync_failed', implode('; ', (array) ($result['errors'] ?? ['Sync failed.'])), ['status' => 502]);
            return ['status' => 'success', 'message' => 'Sync completed.', 'last_sync' => $result['last_sync'], 'details' => $result];
        } catch (\Throwable $error) {
            CB_Sync_Status::record('master', ['success' => false, 'errors' => [$error->getMessage()]]);
            return new \WP_Error('cb_sync_failed', $error->getMessage(), ['status' => 502]);
        }
    }
    private static function period(\WP_REST_Request $request): array {
        $months = max(1, min(12, (int) $request->get_param('months')));
        $end = new \DateTimeImmutable('now', wp_timezone());
        $start = $end->modify('-' . $months . ' months');
        return [$start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
    }
    private static function meeting_metrics(\WP_REST_Request $request): array {
        global $wpdb; [$start, $end] = self::period($request);
        $types = $wpdb->get_results("SELECT id,name,product_id,uuid FROM {$wpdb->prefix}cb_event_types ORDER BY name", ARRAY_A) ?: [];
        $events = $wpdb->get_results($wpdb->prepare("SELECT event_type_id,order_id,start_time FROM {$wpdb->prefix}cb_scheduled_events WHERE status IN ('active','completed') AND start_time BETWEEN %s AND %s", $start, $end), ARRAY_A) ?: [];
        $result = []; $links = []; $orders = []; $excluded = [];
        foreach ($types as $type) $result[(int) $type['id']] = ['id' => (int) $type['id'], 'name' => $type['name'], 'bookings' => 0, 'revenue' => 0.0, 'last_booking' => null, 'product_id' => (int) $type['product_id'], 'uuid' => $type['uuid']];
        foreach ($events as $event) {
            $id = (int) $event['event_type_id']; if (!isset($result[$id])) continue;
            $result[$id]['bookings']++;
            $iso = str_replace(' ', 'T', $event['start_time']) . 'Z';
            if (!$result[$id]['last_booking'] || $iso > $result[$id]['last_booking']) $result[$id]['last_booking'] = $iso;
            $order_id = absint($event['order_id']); if (!$order_id || isset($links[$order_id][$id])) continue;
            $links[$order_id][$id] = true;
            if (!array_key_exists($order_id, $orders)) $orders[$order_id] = wc_get_order($order_id);
            $order = $orders[$order_id]; if (!$order || !in_array($order->get_status(), ['processing','completed','refunded'], true)) continue;
            if ($order->get_currency() !== get_woocommerce_currency()) { $excluded[$order_id] = true; continue; }
            $result[$id]['revenue'] += self::order_net_sales($order, $result[$id]);
        }
        foreach ($result as &$row) { $row['revenue'] = round($row['revenue'], wc_get_price_decimals()); unset($row['product_id'], $row['uuid']); } unset($row);
        usort($result, static fn($a,$b) => $b['bookings'] <=> $a['bookings']);
        return ['months' => max(1,min(12,(int) $request->get_param('months'))), 'events' => array_values($result), 'excluded_currency_orders' => count($excluded)];
    }

    private static function order_net_sales($order, array $type): float {
        $total = 0.0;
        foreach ($order->get_items() as $item_id => $item) {
            $item_uuid = (string) ($item->get_meta('_cb_booking_event_uuid', true) ?: $item->get_meta('_cb_event_uuid', true));
            if (($item_uuid && $item_uuid === $type['uuid']) || (!$item_uuid && $type['product_id'] && (int) $item->get_product_id() === $type['product_id'])) {
                $total += (float) $item->get_total() - abs((float) $order->get_total_refunded_for_item($item_id));
            }
        }
        return $total;
    }
}
