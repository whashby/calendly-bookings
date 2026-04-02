<?php
//includes/modules/class-cb-api-proxy.php
namespace Calendly_Bookings\Modules;

use Calendly_Bookings\CB_Constants;

if (!defined('ABSPATH')) exit;

final class CB_API_Proxy {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function can_manage(): bool {
        return current_user_can('manage_options');
    }

    public static function register_routes(): void {
        $ns = 'calendly-bookings/v1';

		// Debug: dump event types
		register_rest_route($ns, '/debug-event-types', [
			'methods'  => 'GET',
			'callback' => [__CLASS__, 'rest_debug_event_types'],
			'permission_callback' => 'can_manage',
			'args' => [
				'uuid' => ['required' => false, 'type' => 'string']
			],
		]);
		
		register_rest_route($ns, '/meeting-details', [
		    'methods' => 'GET',
		    'callback' => [__CLASS__, 'get_meeting_details'],
		    'permission_callback' => '__return_true',
		    'args' => [
		        'event_uuid' => [
		            'required' => true,
		            'sanitize_callback' => 'sanitize_text_field',
		        ],
		        'invitee_uuid' => [ 
		            'required' => true, 
		            'sanitize_callback' => 'sanitize_text_field', 
		        ], 
		    ],
		]);
		
        // Sync
        register_rest_route($ns, '/sync', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_sync'],
            'permission_callback' => [__CLASS__, 'can_manage'],
            'args'                => [
                'count' => ['type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint'],
            ],
        ]);

        // Event types (list from DB, not raw API) - include scheduling_url
        register_rest_route($ns, '/event-types', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_event_types_list'],
            'permission_callback' => '__return_true',
        ]);

        // Event types sync (all)
        register_rest_route($ns, '/event-types/sync', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_event_types_sync_all'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);

        // Event type sync (single UUID)
        register_rest_route($ns, '/event-types/(?P<uuid>[0-9a-fA-F-]+)/sync', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_event_types_sync_one'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);

        // Availability (support uuid or full uri)
        register_rest_route($ns, '/event-availability', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_event_availability'],
            'permission_callback' => '__return_true',
            'args'                => [
                'event_type_uri' => ['required' => false],
                'uuid'           => ['required' => false],
                'start_iso'      => ['required' => false],
            ],
        ]);

        // Scheduled events passthrough (persists via CB_API)
        register_rest_route($ns, '/scheduled-events', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'restScheduledEvents'],
            'permission_callback' => [__CLASS__, 'can_manage'],
            'args'                => ['count' => ['required' => false, 'type' => 'integer']],
        ]);


        // Invitees
        register_rest_route($ns, '/event-invitees', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_event_invitees'],
            'permission_callback' => [__CLASS__, 'can_manage'],
            'args'                => ['scheduled_event_uri' => ['required' => true]],
        ]);

        // Cache
        register_rest_route($ns, '/clear-cache', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_clear_cache'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);

        register_rest_route($ns, '/manual-test', [
            'methods'=>'POST',
            'callback'=>[__CLASS__,'restManualTest'],
            'permission_callback'=>[__CLASS__,'can_manage'],
        ]);

        register_rest_route($ns, '/save-settings', [
            'methods'=>'POST',
            'callback'=>[__CLASS__,'rest_save_settings'],
            'permission_callback'=>[__CLASS__,'can_manage'],
            'args'=>['token'=>['required'=>false],'uuid'=>['required'=>false]],
        ]);

 /*       // Clear API cache
        register_rest_route($ns, '/maintenance/clear-cache', [
            'methods'             => 'POST',
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

        // Rebuild product links
        register_rest_route($ns, '/maintenance/rebuild-links', [
            'methods'             => \WP_REST_Server::CREATABLE,
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
	
        register_rest_route($ns, '/schedule', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_schedule'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce'); // adjust as needed
            },
            'args' => [
                'order_id' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
            ],
        ]);
*/
			
		
    }
	
public static function rest_sync(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
    $res = CB_API::instance()->sync((int) ($r->get_param('count') ?: 100));

    if (empty($res['success'])) {
        CB_Audit_Log::log('sync_failed', 'scheduled_events', '', ['errors' => $res['errors']], 'error');
        return new \WP_Error('sync_failed', wp_json_encode($res['errors']), ['status' => 500]);
    }

    // Fetch scheduled events explicitly to return them
    $events = CB_API::instance()->get_scheduled_events([],'admin', absint($r->get_param('count') ?: 50));

    CB_Audit_Log::log('sync_complete', 'scheduled_events', '', [
        'event_types_upserted' => $res['event_types_upserted'] ?? 0,
        'events_upserted'      => $res['events_upserted'] ?? 0,
        'events_count'         => count($events),
    ], 'info');

    return new \WP_REST_Response([
        'success'               => true,
        'message'               => __('Sync complete', 'calendly-bookings'),
        'event_types_upserted'  => $res['event_types_upserted'] ?? 0,
        'events_upserted'       => $res['events_upserted'] ?? 0,
        'scheduled_events'      => $events,
        'errors'                => $res['errors'] ?? []
    ], 200);
}

    public static function rest_event_types_list(): \WP_REST_Response {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id, uuid, name, duration, uri, scheduling_url, product_id FROM {$wpdb->prefix}cb_event_types ORDER BY name ASC", ARRAY_A);
        return new \WP_REST_Response(['success' => true, 'data' => $rows], 200);
    }

    public static function rest_event_types_sync_all(): \WP_REST_Response|\WP_Error {
        $res = CB_API::instance()->get_event_types(null, true);
        if (!empty($res['error'])) {
            return new \WP_Error('event_types_failed', $res['error'], ['status' => 500]);
        }
        return new \WP_REST_Response(['success' => true, 'count' => (int) ($res['count'] ?? count($res['collection'] ?? []))], 200);
    }

    public static function rest_event_types_sync_one(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $uuid = sanitize_text_field((string) $r['uuid']);
        $res  = CB_API::instance()->get_event_types($uuid, true);
        if (!empty($res['error'])) {
            return new \WP_Error('event_type_failed', $res['error'], ['status' => 404]);
        }
        return new \WP_REST_Response(['success' => true, 'data' => $res['collection'][0] ?? null], 200);
    }

    public static function rest_event_availability(\WP_REST_Request $r): \WP_REST_Response {
        $uri  = sanitize_text_field((string) ($r->get_param('event_type_uri') ?: ''));
        $uuid = sanitize_text_field((string) ($r->get_param('uuid') ?: ''));
        $start_iso = sanitize_text_field((string) ($r->get_param('start_iso') ?: gmdate('c')));

        if (!$uri && $uuid) {
            $uri = 'https://api.calendly.com/event_types/' . $uuid;
        }
        if (!$uri || !str_starts_with($uri, 'https://api.calendly.com/event_types/')) {
            return new \WP_REST_Response(['success'=>false,'message'=>'Invalid event_type'], 400);
        }

        $res = CB_API::instance()->get_event_type_available_times($uuid, $start_iso);
        if (!empty($res['error'])) {
            return new \WP_REST_Response(['success'=>false,'message'=>$res['error']], 200);
        }
        return new \WP_REST_Response(['success'=>true,'data'=>$res['collection'] ?? []], 200);
    }

    public static function restScheduledEvents(\WP_REST_Request $r): \WP_REST_Response {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            CB_Audit_Log::log('unauthorized', 'scheduled_events', '', [], 'warning');
            return new \WP_REST_Response(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $count = absint($r->get_param('count') ?: 50);
        $events = CB_API::instance()->get_scheduled_events('admin', $count);

        CB_Audit_Log::log('rest_fetch', 'scheduled_events', '', [
            'context' => 'admin',
            'count'   => count($events)
        ], 'info');

        set_transient('cb_last_sync', [
            'time'   => current_time('mysql'),
            'count'  => count($events),
            'source' => 'scheduled-events',
        ], MINUTE_IN_SECONDS * 30);

        return new \WP_REST_Response([
            'success'          => true,
            'events_upserted'  => count($events),
            'scheduled_events' => $events,
        ], 200);
    }

    public static function rest_event_invitees(\WP_REST_Request $r): \WP_REST_Response {
        $uri = sanitize_text_field((string)$r->get_param('scheduled_event_uri'));
        if (!$uri || !str_starts_with($uri,'https://api.calendly.com/scheduled_events/')) {
            return new \WP_REST_Response(['success'=>false,'message'=>'Invalid scheduled_event_uri'], 400);
        }
        $res = CB_API::instance()->get_event_invitees($uri);
        if (!empty($res['error'])) {
            return new \WP_REST_Response(['success'=>false,'message'=>$res['error']], 200);
        }
        return new \WP_REST_Response(['success'=>true,'data'=>$res['collection'] ?? []], 200);
    }

    public static function rest_clear_cache(): \WP_REST_Response {
        CB_API::clear_cache();
        return new \WP_REST_Response(['success'=>true,'message'=>__('API cache cleared', 'calendly-bookings')], 200);
    }
    
    public static function restManualTest(): \WP_REST_Response {
        $res = CB_API::instance()->manual_connection_test();
        return new \WP_REST_Response($res,200);
    }
	
	
public static function rest_save_settings(\WP_REST_Request $r): \WP_REST_Response {
    $token = trim(sanitize_text_field((string)($r->get_param('token') ?? '')));
    $uuid  = trim(sanitize_text_field((string)($r->get_param('uuid') ?? '')));

    if ($uuid !== '' && !preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
        if (preg_match('/([0-9a-fA-F-]{36})$/', $uuid, $m)) {
            $uuid = $m[1];
        } else {
            CB_Audit_Log::log('invalid_uuid', 'settings', '', ['uuid' => $uuid], 'error');
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Invalid UUID format.', 'calendly-bookings')
            ], 200);
        }
    }

    // Test scheduled events connectivity
    //$test = $api->get_scheduled_events('admin', 1);
    $test = CB_API::instance()->manual_connection_test();
    if (empty($test)) {
        CB_Audit_Log::log('connection_failed', 'scheduled_events', '', ['reason' => 'no events'], 'error');
        return new \WP_REST_Response([
            'success' => false,
            'message' => __('Connection test failed: no events returned.', 'calendly-bookings')
        ], 200);
    }

    if ($token !== '') { update_option(CB_Constants::OPT_API_TOKEN, $token, false); }
    if ($uuid !== '') { update_option(CB_Constants::OPT_USER_UUID, $uuid, false); }

    // Sync upcoming events
    //$sync = $api->get_scheduled_events('admin', 100);
    $sync = CB_API::instance()->sync_scheduled_events(100,false);

    CB_Audit_Log::log('settings_saved', 'scheduled_events', '', [
        'token_set' => !empty($token),
        'uuid_set'  => !empty($uuid),
        'synced'    => count($sync)
    ], 'info');

    $message = sprintf(
        __('Settings saved and connection successful. Synced %d upcoming events.', 'calendly-bookings'),
        count($sync)
    );

    return new \WP_REST_Response([
        'success' => true,
        'message' => $message,
        'sync'    => $sync
    ], 200);
}

/*
public static function rest_clear_api_cache(\WP_REST_Request $r): \WP_REST_Response {
    global $wpdb;

    $scope = $r->get_param('scope') ?: 'all';
    $deleted = [
        'transients' => 0,
        'options'    => 0,
    ];
    $errors = [];

    $option_keys = [
        CB_Constants::OPT_LAST_SYNC,
    ];

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
        if (get_option($key, null) !== null) {
            if (delete_option($key)) $deleted['options']++;
        }
    }

    CB_Audit_Log::log('clear_cache', 'maintenance', '', [
        'scope'   => $scope,
        'deleted' => $deleted,
        'errors'  => $errors
    ], empty($errors) ? 'info' : 'error');

    return new \WP_REST_Response([
        'success'  => empty($errors),
        'scope'    => $scope,
        'deleted'  => $deleted,
        'errors'   => $errors,
    ], 200);
}

public static function rest_rebuild_product_links(\WP_REST_Request $r): \WP_REST_Response {
    global $wpdb;

    $uuids   = (array) ($r->get_param('uuids') ?: []);
    $limit   = (int) ($r->get_param('limit') ?: 200);
    $dry_run = (bool) $r->get_param('dry_run');
    $force   = (bool) $r->get_param('force');

    $table = $wpdb->prefix . 'cb_event_types';

    if (!empty($uuids)) {
        $placeholders = implode(',', array_fill(0, count($uuids), '%s'));
        $sql = $wpdb->prepare("SELECT uuid, name, duration, uri, scheduling_url, product_id, meta FROM {$table} WHERE uuid IN ($placeholders) ORDER BY name ASC", ...$uuids);
    } else {
        $sql = $wpdb->prepare("SELECT uuid, name, duration, uri, scheduling_url, product_id, meta FROM {$table} ORDER BY name ASC LIMIT %d", $limit);
    }

    $rows = $wpdb->get_results($sql, ARRAY_A);

    $stats = [
        'processed' => 0,
        'linked'    => 0,
        'relinked'  => 0,
        'skipped'   => 0,
        'errors'    => [],
        'details'   => [],
    ];

    if (!$rows) {
        CB_Audit_Log::log('rebuild_links', 'maintenance', '', ['reason' => 'no event types found'], 'warning');
        return new \WP_REST_Response([
            'success'  => true,
            'message'  => 'No event types found to process.',
            'stats'    => $stats,
        ], 200);
    }

    if (!class_exists('\Calendly_Bookings\Modules\CB_WC_Sync')) {
        CB_Audit_Log::log('rebuild_links_failed', 'maintenance', '', ['reason' => 'CB_WC_Sync not available'], 'error');
        return new \WP_REST_Response([
            'success' => false,
            'message' => 'CB_WC_Sync not available.',
        ], 200);
    }

    $api = new CB_API();

    foreach ($rows as $row) {
        $stats['processed']++;
        $uuid       = $row['uuid'];
        $product_id = (int) ($row['product_id'] ?: 0);

        $needs_link = $force || !$product_id || ($product_id && get_post_type($product_id) !== 'product');
        if (!$needs_link && get_post_status($product_id) !== 'publish') {
            $needs_link = true;
        }

        if (!$needs_link) {
            $stats['skipped']++;
            $stats['details'][] = ['uuid' => $uuid, 'action' => 'skip', 'reason' => 'already_linked'];
            CB_Audit_Log::log('rebuild_links_skip', 'event', $uuid, ['reason' => 'already_linked'], 'info');
            continue;
        }

        $event_type = [];
        if (!empty($row['meta'])) {
            $decoded = json_decode($row['meta'], true);
            if (is_array($decoded)) $event_type = $decoded;
        }

        if (empty($event_type)) {
            $et = $api->get_event_types($uuid, true);
            if (!empty($et['error']) || empty($et['collection'][0])) {
                $stats['errors'][] = ['uuid' => $uuid, 'error' => $et['error'] ?? 'event_type_not_found'];
                $stats['details'][] = ['uuid' => $uuid, 'action' => 'error', 'reason' => ($et['error'] ?? 'event_type_not_found')];
                CB_Audit_Log::log('rebuild_links_error', 'event', $uuid, ['error' => $et['error'] ?? 'event_type_not_found'], 'error');
                continue;
            }
            $event_type = $et['collection'][0];
        }

        if ($dry_run) {
            $stats['details'][] = ['uuid' => $uuid, 'action' => 'dry_run', 'would_link' => true, 'existing_product_id' => $product_id ?: null];
            CB_Audit_Log::log('rebuild_links_dry_run', 'event', $uuid, ['existing_product_id' => $product_id ?: null], 'info');
            continue;
        }

        try {
            $new_pid = CB_WC_Sync::sync_from_event_type($event_type, $product_id ?: null);

            if (is_wp_error($new_pid)) {
                $stats['errors'][] = ['uuid' => $uuid, 'error' => $new_pid->get_error_message()];
                $stats['details'][] = ['uuid' => $uuid, 'action' => 'error', 'reason' => $new_pid->get_error_message()];
                CB_Audit_Log::log('rebuild_links_error', 'event', $uuid, ['error' => $new_pid->get_error_message()], 'error');
                continue;
            }

            if ($new_pid && $new_pid !== $product_id) {
                $wpdb->update($table, ['product_id' => (int) $new_pid], ['uuid' => $uuid], ['%d'], ['%s']);
            }

            if ($product_id && $new_pid === $product_id) {
                $stats['relinked']++;
                $stats['details'][] = ['uuid' => $uuid, 'action' => 'relinked', 'product_id' => $new_pid];
                CB_Audit_Log::log('rebuild_links_relinked', 'event', $uuid, ['product_id' => $new_pid], 'info');
            } else {
                $stats['linked']++;
                $stats['details'][] = ['uuid' => $uuid, 'action' => 'linked', 'product_id' => $new_pid];
                CB_Audit_Log::log('rebuild_links_linked', 'event', $uuid, ['product_id' => $new_pid], 'info');
            }
        } catch (\Throwable $e) {
            $stats['errors'][] = ['uuid' => $uuid, 'error' => $e->getMessage()];
            $stats['details'][] = ['uuid' => $uuid, 'action' => 'error', 'reason' => $e->getMessage()];
            CB_Audit_Log::log('rebuild_links_exception', 'event', $uuid, ['error' => $e->getMessage()], 'error');
        }
    }

    $message = sprintf(
        'Processed %d event type(s): linked %d, relinked %d, skipped %d, errors %d.',
        $stats['processed'], $stats['linked'], $stats['relinked'], $stats['skipped'], count($stats['errors'])
    );

    CB_Audit_Log::log('rebuild_links_summary', 'maintenance', '', [
        'processed' => $stats['processed'],
        'linked'    => $stats['linked'],
        'relinked'  => $stats['relinked'],
        'skipped'   => $stats['skipped'],
        'errors'    => count($stats['errors'])
    ], empty($stats['errors']) ? 'info' : 'error');

    return new \WP_REST_Response([
        'success' => empty($stats['errors']),
        'message' => $message,
        'stats'   => $stats,
    ], 200);
}

*/


public static function rest_debug_event_types(\WP_REST_Request $req): \WP_REST_Response {
    $uuid = $req->get_param('uuid');

    // Fetch without persisting, so you see raw API data
    $result = CB_API::instance()->get_event_types($uuid ?: null, false);

    CB_Audit_Log::log('debug_event_types', 'event', $uuid ?: 'ALL', [
        'success' => empty($result['error']),
        'error'   => $result['error'] ?? null
    ], empty($result['error']) ? 'info' : 'warning');

    return new \WP_REST_Response([
        'success' => empty($result['error']),
        'uuid'    => $uuid ?: 'ALL',
        'data'    => $result
    ], 200);
}

/*public static function handle_schedule(\WP_REST_Request $request): \WP_REST_Response {
    $order_id = absint($request->get_param('order_id'));
    $order    = wc_get_order($order_id);

    if (!$order) {
        CB_Audit_Log::log('schedule_meeting_failed', 'order', (string) $order_id, ['reason' => 'order not found'], 'error');
        return new \WP_REST_Response(['error' => 'Order not found'], 404);
    }

    $iso_time = $order->get_meta('_cb_meeting_time');
    $notes    = $order->get_meta('_cb_meeting_notes');

    // Get scheduling URL from first product
    $scheduling_url = '';
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $url = get_post_meta($product_id, '_cb_scheduling_url', true);
        if ($url) {
            $scheduling_url = $url;
            break;
        }
    }

    if (!$scheduling_url || !$iso_time) {
        CB_Audit_Log::log('schedule_meeting_failed', 'order', (string) $order_id, ['reason' => 'missing scheduling URL or meeting time'], 'error');
        return new \WP_REST_Response(['error' => 'Missing scheduling URL or meeting time'], 400);
    }

    $api = new CB_API();
    $prefill = [
        'name'  => $order->get_formatted_billing_full_name(),
        'email' => $order->get_billing_email(),
        'a1'    => $order->get_order_number(),
        'a2'    => $notes,
    ];

    $res = $api->schedule_meeting($scheduling_url, $iso_time, $prefill);

    if (!empty($res['error'])) {
        CB_Audit_Log::log('schedule_meeting_failed', 'order', (string) $order_id, ['error' => $res['error']], 'error');
        return new \WP_REST_Response($res, 400);
    }

    // Persist scheduled event UUID in order meta
    if (!empty($res['resource']['uuid'])) {
        $order->update_meta_data('_cb_scheduled_event_uuid', $res['resource']['uuid']);
        $order->save();
    }

    CB_Audit_Log::log('schedule_meeting', 'order', (string) $order_id, [
        'scheduling_url' => $scheduling_url,
        'iso_time'       => $iso_time,
        'success'        => true
    ], 'info');

    return new \WP_REST_Response([
        'success'         => true,
        'order_id'        => $order_id,
        'scheduled_event' => $res['resource'] ?? [],
    ], 200);
}
    */
public static function get_meeting_details(\WP_REST_Request $request): \WP_REST_Response {
    $event_uuid   = sanitize_text_field($request->get_param('event_uuid'));
    $invitee_uuid = sanitize_text_field($request->get_param('invitee_uuid'));

    $results = CB_API::instance()->get_event_details($event_uuid, $invitee_uuid);

    if (isset($results['error']) && $results['error'] === true) {
        CB_Audit_Log::log('get_meeting_details_failed', 'event', $event_uuid, [
            'invitee_uuid' => $invitee_uuid,
            'error'        => $results['message'] ?? 'Unknown error'
        ], 'error');

        return new \WP_REST_Response([
            'error'   => true,
            'message' => $results['message'] ?? 'Unknown error',
            'status'  => $results['status'] ?? 500,
        ], $results['status'] ?? 500);
    }

    CB_Audit_Log::log('get_meeting_details', 'event', $event_uuid, [
        'invitee_uuid' => $invitee_uuid,
        'success'      => true
    ], 'info');

    return new \WP_REST_Response($results, 200);
}


}
