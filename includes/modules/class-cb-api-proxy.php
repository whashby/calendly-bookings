<?php

namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;

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
/*
        register_rest_route($ns, '/manual-test', [
            'methods'=>'POST',
            'callback'=>[__CLASS__,'rest_manual_test'],
            'permission_callback'=>[__CLASS__,'can_manage'],
        ]);

        register_rest_route($ns, '/save-settings', [
            'methods'=>'POST',
            'callback'=>[__CLASS__,'rest_save_settings'],
            'permission_callback'=>[__CLASS__,'can_manage'],
            'args'=>['token'=>['required'=>false],'uuid'=>['required'=>false]],
        ]);
*/
    }
	
public static function rest_sync(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
    $res = CB_API::instance()->sync(get_option(CB_Constants::OPT_MIN_START_DATE), true);

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
        $rows = CB_API::instance()->get_event_types(true);

        if (!is_array($rows)) {
            return new \WP_REST_Response(['success' => false, 'data' => [], 'message' => __('Failed to load event types', 'calendly-bookings')], 500);
        }

        return new \WP_REST_Response(['success' => true, 'data' => $rows], 200);
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
        if (!empty($res['error'])) return new \WP_REST_Response(['success'=>false,'message'=>$res['error']], 200);
        return new \WP_REST_Response(['success'=>true,'data'=>$res['collection'] ?? []], 200);
    }

	public static function rest_scheduled_events(\WP_REST_Request $r): \WP_REST_Response {
		if (!is_user_logged_in() || !current_user_can('manage_options')) {
			CB_Audit_Log::log('unauthorized', 'scheduled_events', '', [], 'warning');
			return new \WP_REST_Response(['success' => false, 'message' => 'Unauthorized'], 401);
		}

		$count = absint($r->get_param('count') ?: 50);
		$events = CB_API::instance()->get_scheduled_events([], 'admin', $count);

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
        if (!empty($res['error'])) return new \WP_REST_Response(['success'=>false,'message'=>$res['error']], 200);
        return new \WP_REST_Response(['success'=>true,'data'=>$res['collection'] ?? []], 200);
    }

    public static function rest_clear_cache(): \WP_REST_Response {
        CB_API::clear_cache();
        return new \WP_REST_Response(['success'=>true,'message'=>__('API cache cleared', 'calendly-bookings')], 200);
    }
    
    public static function rest_manual_test(\WP_REST_Request $r): \WP_REST_Response {
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

        CB_API::instance()->set_credentials($token ?: null, $uuid ?: null);

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

        if ($token !== '') {
            update_option(CB_Constants::OPT_API_TOKEN, $token, false);
        }
        if ($uuid !== '') {
            update_option(CB_Constants::OPT_USER_UUID, $uuid, false);
        }

        // Sync upcoming events
        //$sync = $api->get_scheduled_events('admin', 100);
        $sync = CB_API::instance()->sync_scheduled_events(100, '', false);

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

    public static function rest_debug_event_types(\WP_REST_Request $req): \WP_REST_Response {
        $uuid = $req->get_param('uuid');

        // Fetch without persisting, so you see raw API data
        $result = CB_API::instance()->get_event_types(false);

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

}
