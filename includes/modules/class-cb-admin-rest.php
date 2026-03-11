<?php
namespace Calendly_Bookings\Modules;

use WP_REST_Request;

if (!defined('ABSPATH')) exit;

final class CB_Admin_Rest {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        $ns = 'calendly-bookings/v1';

        register_rest_route($ns, '/scheduled-events/locations', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'cb_get_locations'],
            'permission_callback' => '__return_true', // adjust if you want auth
        ]);

        // Fetch single event
        register_rest_route($ns, '/scheduled-events/(?P<uuid>[a-z0-9\-]+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_event'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);

        register_rest_route($ns, '/scheduled-events/view/(?P<uuid>[a-z0-9\-]+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_event'],
            'permission_callback' => '__return_true',
        ]);

        // Update single event
        register_rest_route($ns, '/scheduled-events/(?P<uuid>[a-z0-9\-]+)', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'update_event'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);

        register_rest_route($ns, '/scheduled-events/update/(?P<uuid>[a-z0-9\-]+)', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'update_event'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);

        // Bulk update (expects JSON body with uuids[])
        register_rest_route($ns, '/scheduled-events/bulk-update', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'bulk_update_scheduled_events'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);

        // Fetch history
        register_rest_route($ns, '/scheduled-events/invitee-history/(?P<invitee>[\p{L}0-9\.\-\_%\s]+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_invitee_history'],
            'permission_callback' => '__return_true',
        ]);

        
		
		register_rest_route($ns, '/audit-log', [
			'methods'             => 'GET',
			'callback'            => [\Calendly_Bookings\Modules\CB_Audit_Log::class, 'rest_fetch'],
            'permission_callback' => [__CLASS__, 'can_manage'],
			'args' => [
				's'       => ['sanitize_callback' => 'sanitize_text_field'],
				'level'   => ['sanitize_callback' => 'sanitize_text_field'],
				'action'  => ['sanitize_callback' => 'sanitize_text_field'],
				'context' => ['sanitize_callback' => 'sanitize_text_field'],
				'paged'   => ['sanitize_callback' => 'absint'],
			],
		]);

        // WooCommerce linking/sync
        register_rest_route($ns, '/wc/link-product', [
            'methods'=>'POST',
			'callback'=>[__CLASS__,'rest_wc_link'],
			'permission_callback'=>[__CLASS__,'can_manage'],
            'args'=>[
				'uuid'=>['required'=>true],
				'product_id'=>['required'=>true,'type'=>'integer']
			],
        ]);
		
        register_rest_route($ns, '/wc/create-product', [
            'methods' => 'POST',
			'callback' => [__CLASS__, 'rest_wc_create_product'],
			'permission_callback' => [__CLASS__, 'can_manage'],
            'args'=>[
				'uuid' => [
					'required' => true, 
					'type' => 'string'
				]
			],
        ]);        


    }

    public static function can_manage(): bool {
        return current_user_can('manage_options');
    }


    /**
     * Callback for /locations endpoint
     */
    public static function cb_get_locations(WP_REST_Request $request) {
    // Example: fetch from DB or config
    $locations = [
        [ 'id' => 'skeetes-road', 'name' => "Skeete's Road Jackmans, St. Michael" ],
        [ 'id' => 'online', 'name' => "Online (Zoom)" ],
    ];

    return [
        'success' => true,
        'data'    => $locations,
    ];
}

    /**
     * Fetch a single scheduled event by UUID.
     */
    public static function get_event(WP_REST_Request $request) {
        $uuid = sanitize_text_field($request->get_param('uuid'));
        $event = CB_Scheduled_Events::instance()->get_event($uuid);
        return $event ?: ['success' => false, self::error('Event not found', 404)];
    }

    /**
     * Get all sessions and notes for an invitee, optionally filtered by event.
     */
    public static function get_invitee_history(\WP_REST_Request $request) {
        $invitee = sanitize_text_field($request->get_param('invitee'));
        $response = CB_Scheduled_Events::instance()->get_invitee_history($invitee);
        return $response ?: ['success' => false, 'error' => self::error('History not found', 404)];
    }


    /**
     * Update a single scheduled event.
     */
    public static function update_event(WP_REST_Request $request) {
        $uuid = sanitize_text_field($request->get_param('uuid'));
        $data = (array) $request->get_json_params();

        if (!$uuid || empty($data)) {
            return self::error('Missing uuid or data');
        }

        $ok = CB_Scheduled_Events::instance()->update_event($uuid, $data);

        return $ok
            ? ['uuid' => $uuid, 'updated' => $data]
            : self::error('Update failed');
    }

    /**
     * Bulk update multiple scheduled events.
     */
    public static function bulk_update_scheduled_events(WP_REST_Request $request) {
        $params = (array) $request->get_json_params();
        $uuids  = isset($params['uuids']) ? (array) $params['uuids'] : [];
        $status = sanitize_text_field($request->get_param('status'));
        $completed = $status === 'completed' ? 1 : 0;

        if (empty($uuids) || empty($status)) {
            return self::error('Missing uuids or status');
        }

        foreach ($uuids as $uuid) {
            $uuid = sanitize_text_field($uuid);
            $ok = CB_Scheduled_Events::instance()->update_event($uuid, [
                'status'    => $status,
                'completed' => $completed,
            ]);
            if (!$ok) {
                return self::error('Update failed');
            }
        }

        return ['success' => true];
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
            'post_content' => '',
            'post_excerpt' => $event->description ?? '',
            'post_status'  => 'publish',
            'post_type'    => 'product',
            'meta_input'   => [
                '_cb_event_uuid'     => $uuid,
                '_cb_scheduling_url' => esc_url_raw((string)($event->scheduling_url ?? '')),
                '_price'             => '',
                '_stock_status'      => 'instock'
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

		// Update product meta with event UUID + scheduling URL
		update_post_meta($product_id, '_cb_event_uuid', $uuid);

		$scheduling_url = !empty($event->scheduling_url) ? $event->scheduling_url : '';
		if ($scheduling_url) {
			update_post_meta($product_id, '_cb_scheduling_url', esc_url_raw($scheduling_url));
		}

		// Update event record with product ID (and backfill scheduling_url if missing)
		$update_data = ['product_id' => $product_id];
		if ($scheduling_url && empty($event->scheduling_url)) {
			$update_data['scheduling_url'] = esc_url_raw($scheduling_url);
		}
		$wpdb->update($table, $update_data, ['uuid' => $uuid]);

		// Optional: store product_id in event meta JSON
		$meta = json_decode($event->meta ?? '{}', true);
		$meta['linked_product_id'] = $product_id;
		$wpdb->update($table, ['meta' => wp_json_encode($meta)], ['uuid' => $uuid]);

		set_transient('cb_event_notice', [
			'type'    => 'success',
			'message' => sprintf(__('Linked event "%s" to product #%d.', 'calendly-bookings'), $event->name, $product_id)
		], 30);

		return new \WP_REST_Response(['success' => true, 'message' => 'Linked successfully.', 'product_id' => $product_id], 200);
	}

	private static function error(string $message, int $status = 400) {
        return new \WP_Error('cb_rest_error', $message, ['status' => $status]);
    }
}
