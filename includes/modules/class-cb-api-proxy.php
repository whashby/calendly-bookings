<?php
// includes/modules/class-cb-api-proxy.php
namespace Calendly_Bookings\Modules;

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

        // Sync
        register_rest_route($ns, '/sync', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_sync'],
            'permission_callback' => [__CLASS__, 'can_manage'],
            'args'                => [
                'count' => ['type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint'],
            ],
        ]);

        // Event types (list from DB, not raw API)
        register_rest_route($ns, '/event-types', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_event_types_list'],
            'permission_callback' => [__CLASS__, 'can_manage'],
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
            'args'                => [
                'event_type_uri' => ['required' => false],
                'uuid'           => ['required' => false],
                'start_iso'      => ['required' => false],
            ],
        ]);

        // Scheduled events passthrough (persists via CB_API)
        register_rest_route($ns, '/scheduled-events', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_scheduled_events'],
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
            'methods'=>'GET',
            'callback'=>[__CLASS__,'rest_manual_test'],
            'permission_callback'=>[__CLASS__,'can_manage'],
        ]);

        register_rest_route($ns, '/save-settings', [
            'methods'=>'POST',
            'callback'=>[__CLASS__,'rest_save_settings'],
            'permission_callback'=>[__CLASS__,'can_manage'],
            'args'=>['token'=>['required'=>false],'uuid'=>['required'=>false]],
        ]);

        // WooCommerce linking/sync
       register_rest_route($ns, '/wc/links', [
            'methods'=>'GET','callback'=>[__CLASS__,'rest_wc_links'],'permission_callback'=>[__CLASS__,'can_manage'],
        ]);
       register_rest_route($ns, '/wc/link', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_wc_link'],'permission_callback'=>[__CLASS__,'can_manage'],
            'args'=>['uuid'=>['required'=>true],'product_id'=>['required'=>true,'type'=>'integer']],
        ]);
       register_rest_route($ns, '/wc/sync', [
            'methods'=>'POST','callback'=>[__CLASS__,'rest_wc_sync'],'permission_callback'=>[__CLASS__,'can_manage'],
            'args'=>['uuid'=>['required'=>true],'product_id'=>['required'=>false,'type'=>'integer']],
        ]);
		register_rest_route($ns, '/wc/create-product', [
			'methods' => 'POST','callback' => [__CLASS__, 'rest_wc_create_product'],'permission_callback' => [__CLASS__, 'can_manage'],
			'args'=>['uuid' => ['required' => true, 'type' => 'string']],
		]);
		register_rest_route($ns, '/wc/delete-product', [
			'methods' => 'POST','callback' => [__CLASS__, 'rest_wc_delete_product'],'permission_callback' => [__CLASS__, 'can_manage'],
			'args' => ['uuid' => ['required' => true, 'type' => 'string']],
		]);
       register_rest_route($ns, '/wc/create-all', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_wc_create_all'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);
       register_rest_route($ns, '/wc/delete-all', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_wc_delete_all'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);

		// Clear API cache
		register_rest_route($ns, '/maintenance/clear-cache', [
			'methods'             => \WP_REST_Server::CREATABLE,
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
		
    }

    public static function rest_sync(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $api = new CB_API();
        $res = $api->sync((int) ($r->get_param('count') ?: 100));
        if (empty($res['success'])) return new \WP_Error('sync_failed', wp_json_encode($res['errors']), ['status' => 500]);
        return new \WP_REST_Response($res, 200);
    }

    public static function rest_event_types_list(): \WP_REST_Response {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT uuid, name, duration, uri, product_id FROM {$wpdb->prefix}cb_event_types ORDER BY name ASC", ARRAY_A);
        return new \WP_REST_Response(['success' => true, 'data' => $rows], 200);
    }

    public static function rest_event_types_sync_all(): \WP_REST_Response|\WP_Error {
        $api = new CB_API();
        $res = $api->get_event_types(null, true);
        if (!empty($res['error'])) return new \WP_Error('event_types_failed', $res['error'], ['status' => 500]);
        return new \WP_REST_Response(['success' => true, 'count' => (int) ($res['count'] ?? count($res['collection'] ?? []))], 200);
    }

    public static function rest_event_types_sync_one(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $uuid = sanitize_text_field((string) $r['uuid']);
        $api  = new CB_API();
        $res  = $api->get_event_types($uuid, true);
        if (!empty($res['error'])) return new \WP_Error('event_type_failed', $res['error'], ['status' => 404]);
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

        $api = new CB_API();
        $res = $api->get_event_type_availability($uri, $start_iso);
        if (!empty($res['error'])) return new \WP_REST_Response(['success'=>false,'message'=>$res['error']], 200);
        return new \WP_REST_Response(['success'=>true,'data'=>$res['collection'] ?? []], 200);
    }

    public static function rest_scheduled_events(\WP_REST_Request $r): \WP_REST_Response {
        $api = new CB_API();
        $res = $api->get_upcoming_events(absint($r->get_param('count') ?: 50));
        if (!empty($res['error'])) return new \WP_REST_Response(['success'=>false,'message'=>$res['error']], 200);
        return new \WP_REST_Response(['success'=>true,'data'=>$res['collection'] ?? []], 200);
    }

    public static function rest_event_invitees(\WP_REST_Request $r): \WP_REST_Response {
        $uri = sanitize_text_field((string)$r->get_param('scheduled_event_uri'));
        if (!$uri || !str_starts_with($uri,'https://api.calendly.com/scheduled_events/')) {
            return new \WP_REST_Response(['success'=>false,'message'=>'Invalid scheduled_event_uri'], 400);
        }
        $api = new CB_API();
        $res = $api->get_event_invitees($uri);
        if (!empty($res['error'])) return new \WP_REST_Response(['success'=>false,'message'=>$res['error']], 200);
        return new \WP_REST_Response(['success'=>true,'data'=>$res['collection'] ?? []], 200);
    }

    public static function rest_clear_cache(): \WP_REST_Response {
        CB_API::clear_cache();
        return new \WP_REST_Response(['success'=>true,'message'=>__('API cache cleared', 'calendly-bookings')], 200);
    }
    
    public static function rest_manual_test(\WP_REST_Request $r): \WP_REST_Response {
        $api = new CB_API(); $res = $api->manual_connection_test();
        return new \WP_REST_Response($res,200);
    }
    
public static function rest_save_settings(\WP_REST_Request $r): \WP_REST_Response {
    $token = trim(sanitize_text_field((string)($r->get_param('token') ?? '')));
    $uuid  = trim(sanitize_text_field((string)($r->get_param('uuid') ?? '')));

    // Validate UUID format before anything else
    if ($uuid !== '' && !preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
        return new \WP_REST_Response([
            'success' => false,
            'message' => __('Invalid UUID format.', 'calendly-bookings')
        ], 200);
    }

    // Test connection BEFORE saving
    $api = new \Calendly_Bookings\Modules\CB_API($token ?: null, $uuid ?: null);
    $test = $api->test_connection();

    if (!empty($test['error'])) {
        return new \WP_REST_Response([
            'success' => false,
            'message' => __('Connection test failed: ', 'calendly-bookings') . $test['error']
        ], 200);
    }

    // Save settings only if connection is valid
    if ($token !== '') update_option(\Calendly_Bookings\CB_Constants::OPT_API_TOKEN, $token, false);
    if ($uuid !== '')  update_option(\Calendly_Bookings\CB_Constants::OPT_USER_UUID, $uuid, false);

    // Trigger full sync
    $sync = $api->sync(100); // pulls event types + scheduled events

    $message = sprintf(
        __('Settings saved and connection successful. Found %d event types. Synced %d upcoming events.', 'calendly-bookings'),
        $test['count'] ?? 0,
        $sync['events_upserted'] ?? 0
    );

    return new \WP_REST_Response([
        'success' => true,
        'message' => $message,
        'sync'    => $sync
    ], 200);
}


    public static function rest_wc_link(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb;
        $uuid       = sanitize_text_field($req->get_param('uuid'));
        $product_id = absint($req->get_param('product_id'));
        $table      = $wpdb->prefix . 'cb_event_types';
    
        // Validate event
        $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE uuid=%s", $uuid));
        if (!$event) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Event not found.'], 200);
        }
    
        // Validate product
        $product = wc_get_product($product_id);
        if (!$product || 'publish' !== get_post_status($product_id)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid or unpublished product.'], 200);
        }
    
        // Update product meta with event UUID
        update_post_meta($product_id, '_cb_event_uuid', $uuid);
    
        // Update event record with product ID
        $wpdb->update($table, ['product_id' => $product_id], ['uuid' => $uuid]);
    
        // Optional: store product_id in event meta JSON if you keep it in `meta` column
        $meta = json_decode($event->meta ?? '{}', true);
        $meta['linked_product_id'] = $product_id;
        $wpdb->update($table, ['meta' => wp_json_encode($meta)], ['uuid' => $uuid]);
    
        // Set transient for admin notice
        set_transient('cb_event_notice', [
            'type'    => 'success',
            'message' => sprintf(__('Linked event "%s" to product #%d.', 'calendly-bookings'), $event->name, $product_id)
        ], 30);
    
        return new \WP_REST_Response(['success' => true, 'message' => 'Linked successfully.'], 200);
    }
    public static function rest_wc_sync(\WP_REST_Request $r): \WP_REST_Response {
        $uuid = sanitize_text_field((string)$r->get_param('uuid'));
        $product_id = absint($r->get_param('product_id') ?: 0);
        if (!$uuid) return new \WP_REST_Response(['success'=>false,'message'=>'Missing uuid'],200);

        $api = new CB_API();
        $types = $api->get_event_types();
        if (!empty($types['error'])) return new \WP_REST_Response(['success'=>false,'message'=>$types['error']],200);

        $match = null;
        foreach (($types['collection'] ?? []) as $t) {
            if (($t['uuid'] ?? '') === $uuid) { $match = $t; break; }
        }
        if (!$match) return new \WP_REST_Response(['success'=>false,'message'=>'Event type not found'],200);

        $pid = CB_WC_Sync::sync_from_event_type($match, $product_id ?: null);
        return new \WP_REST_Response(['success'=> $pid>0, 'product_id'=> $pid], 200);
    }
    public static function rest_wc_create_product( \WP_REST_Request $req ): \WP_REST_Response {
        global $wpdb;
        $uuid  = sanitize_text_field( $req->get_param( 'uuid' ) );
        $table = $wpdb->prefix . 'cb_event_types';
    
        // Get event
        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE uuid=%s", $uuid ) );
        if ( ! $event ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'Event not found.'
            ], 200 );
        }
    
        // Prevent duplicate product creation
        if ( ! empty( $event->product_id ) && get_post( $event->product_id ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'Product already exists for this event.'
            ], 200 );
        }
    
        // Ensure "Meeting" category exists
        $term = term_exists( 'Meeting', 'product_cat' );
        if ( ! $term ) {
            $term = wp_insert_term( 'Meeting', 'product_cat', [
                'slug' => 'meeting'
            ] );
        }
        $category_id = is_array( $term ) ? $term['term_id'] : $term;
    
        // Create product
        $product_id = wp_insert_post( [
            'post_title'   => $event->name,
            'post_content' => '', // Full description (optional)
            'post_excerpt' => $event->description ?? '', // Short description from event
            'post_status'  => 'publish',
            'post_type'    => 'product',
            'meta_input'   => [
                '_cb_event_uuid' => $uuid,
                '_price'         => '', // Set price if needed
                '_stock_status'  => 'instock'
            ]
        ] );
    
        if ( is_wp_error( $product_id ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'Failed to create product.'
            ], 200 );
        }
    
        // Assign category
        wp_set_object_terms( $product_id, [ (int) $category_id ], 'product_cat' );
    
        // Update event record with product ID
        $wpdb->update(
            $table,
            [ 'product_id' => $product_id ],
            [ 'uuid' => $uuid ]
        );
    
        // Optional: store in event meta JSON
        $meta = json_decode( $event->meta ?? '{}', true );
        $meta['linked_product_id'] = $product_id;
        $wpdb->update( $table, [ 'meta' => wp_json_encode( $meta ) ], [ 'uuid' => $uuid ] );
    
        // Log action
        if ( class_exists( 'CB_Audit_Log' ) ) {
            CB_Audit_Log::log(
                'create_product',
                'event',
                $uuid,
                [
                    'product_id'   => $product_id,
                    'event_name'   => $event->name,
                    'description'  => $event->description ?? '',
                    'category'     => 'Meeting'
                ]
            );
        }
    
        return new \WP_REST_Response( [
            'success'    => true,
            'product_id' => $product_id,
            'message'    => sprintf( 'Product #%d created for event "%s".', $product_id, $event->name )
        ], 200 );
    }
	public static function rest_wc_delete_product(\WP_REST_Request $req): \WP_REST_Response {
		global $wpdb;
		$uuid = sanitize_text_field($req->get_param('uuid'));
		$table = $wpdb->prefix . 'cb_event_types';
		$event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE uuid=%s", $uuid));

		if (!$event || !$event->product_id) {
			return new \WP_REST_Response(['success' => false, 'message' => 'No linked product to delete'], 200);
		}

		wp_delete_post((int) $event->product_id, true);
		$wpdb->update($table, ['product_id' => null], ['uuid' => $uuid]);

		return new \WP_REST_Response(['success' => true, 'message' => 'Product successfully deleted.' ], 200);
	}
    public static function rest_wc_create_all( \WP_REST_Request $req ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'cb_event_types';
    
        // Fetch all events without a linked product
        $events = $wpdb->get_results("SELECT * FROM $table WHERE product_id IS NULL OR product_id = 0");
    
        if (empty($events)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'No events found without linked products.'
            ], 200);
        }
    
        // Ensure "Meeting" category exists
        $term = term_exists('Meeting', 'product_cat');
        if (!$term) {
            $term = wp_insert_term('Meeting', 'product_cat', [
                'slug' => 'meeting'
            ]);
        }
        $category_id = is_array($term) ? $term['term_id'] : $term;
    
        $created_count = 0;
        foreach ($events as $event) {
            // Create WooCommerce product
            $product_id = wp_insert_post([
                'post_title'   => $event->name,
                'post_content' => '', // Full description optional
                'post_excerpt' => $event->description ?? '', // Short description from event
                'post_status'  => 'publish',
                'post_type'    => 'product',
                'meta_input'   => [
                    '_cb_event_uuid' => $event->uuid,
                    '_price'         => '', // Set price if needed
                    '_stock_status'  => 'instock'
                ]
            ]);
    
            if (is_wp_error($product_id)) {
                continue; // Skip on error
            }
    
            // Assign category
            wp_set_object_terms($product_id, [(int) $category_id], 'product_cat');
    
            // Update event record
            $wpdb->update(
                $table,
                ['product_id' => $product_id],
                ['uuid' => $event->uuid]
            );
    
            // Optional: store in event meta JSON
            $meta = json_decode($event->meta ?? '{}', true);
            $meta['linked_product_id'] = $product_id;
            $wpdb->update($table, ['meta' => wp_json_encode($meta)], ['uuid' => $event->uuid]);
    
            // Log action
            if (class_exists('CB_Audit_Log')) {
                CB_Audit_Log::log(
                    'create_product_bulk',
                    'event',
                    $event->uuid,
                    [
                        'product_id'  => $product_id,
                        'event_name'  => $event->name,
                        'description' => $event->description ?? '',
                        'category'    => 'Meeting'
                    ]
                );
            }
    
            $created_count++;
        }
    
        return new \WP_REST_Response([
            'success'       => true,
            'created_count' => $created_count,
            'message'       => sprintf('Created %d products for events.', $created_count)
        ], 200);
    }
    public static function rest_wc_delete_all(): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'cb_event_types';
        $events = $wpdb->get_results("SELECT * FROM $table WHERE product_id IS NOT NULL AND product_id>0");
    
        $deleted = 0;
        foreach ($events as $event) {
            if (wp_delete_post((int) $event->product_id, true)) {
                $wpdb->update($table, ['product_id' => null], ['uuid' => $event->uuid]);
                $deleted++;
            }
        }
    
        return new \WP_REST_Response(['success' => true, 'deleted_count' => $deleted], 200);
    }

	public static function rest_clear_api_cache(\WP_REST_Request $r): \WP_REST_Response {
		global $wpdb;

		$scope = $r->get_param('scope') ?: 'all';
		$deleted = [
			'transients' => 0,
			'options'    => 0,
		];
		$errors = [];

		// Known option keys you may wish to reset
		$option_keys = [
			'cb_last_sync',
			// Add others as needed: e.g., 'cb_etag_event_types', 'cb_etag_availability'
		];

		// Transient prefixes used by your CB_API caching (adjust as needed)
		$prefixes = [];
		if ($scope === 'all' || $scope === 'event_types')   $prefixes[] = '_transient_cb_api_event_types';
		if ($scope === 'all' || $scope === 'availability')  $prefixes[] = '_transient_cb_api_availability';
		if ($scope === 'all' || $scope === 'events')        $prefixes[] = '_transient_cb_api_events';

		// Delete transients by prefix (site transients too)
		foreach ($prefixes as $prefix) {
			$like = esc_sql($prefix . '%');
			$rows = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '{$like}'");
			foreach ($rows as $opt) {
				if (delete_option($opt)) $deleted['transients']++;
			}
			// Site transients (multisite)
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

		// Reset options
		foreach ($option_keys as $key) {
			if (get_option($key, null) !== null) {
				if (delete_option($key)) $deleted['options']++;
			}
		}

		// Optional: flush object cache group if you’re using wp_cache_set with groups
		// wp_cache_flush(); // use carefully

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

		// Fetch target event types
		if (!empty($uuids)) {
			$placeholders = implode(',', array_fill(0, count($uuids), '%s'));
			$sql = $wpdb->prepare("SELECT uuid, name, duration, uri, product_id, meta FROM {$table} WHERE uuid IN ($placeholders) ORDER BY name ASC", ...$uuids);
		} else {
			$sql = $wpdb->prepare("SELECT uuid, name, duration, uri, product_id, meta FROM {$table} ORDER BY name ASC LIMIT %d", $limit);
		}

		$rows = $wpdb->get_results($sql, ARRAY_A);

		$stats = [
			'processed'      => 0,
			'linked'         => 0,
			'relinked'       => 0,
			'skipped'        => 0,
			'errors'         => [],
			'details'        => [],
		];

		if (!$rows) {
			return new \WP_REST_Response([
				'success'  => true,
				'message'  => 'No event types found to process.',
				'stats'    => $stats,
			], 200);
		}

		// Ensure we can sync products
		if (!class_exists('\Calendly_Bookings\Modules\CB_WC_Sync')) {
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

			// Determine if we need to (re)link
			$needs_link = $force || !$product_id || ($product_id && get_post_type($product_id) !== 'product');

			if (!$needs_link && get_post_status($product_id) !== 'publish') {
				// Product exists but is not published; treat as needing relink
				$needs_link = true;
			}

			if (!$needs_link) {
				$stats['skipped']++;
				$stats['details'][] = ['uuid' => $uuid, 'action' => 'skip', 'reason' => 'already_linked'];
				continue;
			}

			// Resolve event type object for sync
			$event_type = [];
			if (!empty($row['meta'])) {
				$decoded = json_decode($row['meta'], true);
				if (is_array($decoded)) $event_type = $decoded;
			}

			if (empty($event_type)) {
				// Fallback: fetch from API (single UUID)
				$et = $api->get_event_types($uuid, true);
				if (!empty($et['error']) || empty($et['collection'][0])) {
					$stats['errors'][] = ['uuid' => $uuid, 'error' => $et['error'] ?? 'event_type_not_found'];
					$stats['details'][] = ['uuid' => $uuid, 'action' => 'error', 'reason' => ($et['error'] ?? 'event_type_not_found')];
					continue;
				}
				$event_type = $et['collection[0]'] ?? $et['collection'][0]; // guard both ways
			}

			if ($dry_run) {
				$stats['details'][] = ['uuid' => $uuid, 'action' => 'dry_run', 'would_link' => true, 'existing_product_id' => $product_id ?: null];
				continue;
			}

			try {
				$new_pid = CB_WC_Sync::sync_from_event_type($event_type, $product_id ?: null);

				if (is_wp_error($new_pid)) {
					$stats['errors'][] = ['uuid' => $uuid, 'error' => $new_pid->get_error_message()];
					$stats['details'][] = ['uuid' => $uuid, 'action' => 'error', 'reason' => $new_pid->get_error_message()];
					continue;
				}

				// Update linkage in event types table if changed
				if ($new_pid && $new_pid !== $product_id) {
					$wpdb->update($table, ['product_id' => (int) $new_pid], ['uuid' => $uuid], ['%d'], ['%s']);
				}

				if ($product_id && $new_pid === $product_id) {
					$stats['relinked']++; // treated as refresh
					$stats['details'][] = ['uuid' => $uuid, 'action' => 'relinked', 'product_id' => $new_pid];
				} else {
					$stats['linked']++;
					$stats['details'][] = ['uuid' => $uuid, 'action' => 'linked', 'product_id' => $new_pid];
				}
			} catch (\Throwable $e) {
				$stats['errors'][] = ['uuid' => $uuid, 'error' => $e->getMessage()];
				$stats['details'][] = ['uuid' => $uuid, 'action' => 'error', 'reason' => $e->getMessage()];
			}
		}

		$message = sprintf(
			'Processed %d event type(s): linked %d, relinked %d, skipped %d, errors %d.',
			$stats['processed'], $stats['linked'], $stats['relinked'], $stats['skipped'], count($stats['errors'])
		);

		return new \WP_REST_Response([
			'success' => empty($stats['errors']),
			'message' => $message,
			'stats'   => $stats,
		], 200);
	}





    
}
