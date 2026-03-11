<?php
if (!defined('ABSPATH')) exit;

/**
 * Reschedule cron when the sync interval option changes.
 */
function cb_reschedule_sync($old_value, $new_value) {
    wp_clear_scheduled_hook('cb_sync_event');
    if (!wp_next_scheduled('cb_sync_event')) {
        wp_schedule_event(time(), $new_value, 'cb_sync_event');
    }
}
add_action('update_option_cb_sync_interval', 'cb_reschedule_sync', 10, 2);

/**
 * Hook the sync task to the cron event.
 */
add_action('cb_sync_event', 'cb_run_data_sync');

/**
 * Main sync routine — delegates to CB_API_Proxy::rest_sync
 */
function cb_run_data_sync($source = 'cron') {
    $request  = new \WP_REST_Request('POST', '/calendly-bookings/v1/sync');
    $response = \Calendly_Bookings\Modules\CB_API_Proxy::rest_sync($request);

    if ($response instanceof \WP_REST_Response) {
        $data = $response->get_data();
        update_option('cb_last_sync', [
            'time'   => current_time('mysql'),
            'events' => $data['scheduled_events'] ?? [],
            'count'  => $data['events_upserted'] ?? 0,
            'source' => $source,
        ], false);
    }
}

/**
 * REST endpoint for manual sync trigger (Run Sync Now button).
 */
add_action('rest_api_init', function() {
    register_rest_route('calendly-bookings/v1', '/sync/run', [
        'methods'             => 'POST',
        'callback'            => function() {
            cb_run_data_sync('manual');

            $last_sync = get_option('cb_last_sync', []);
            return new \WP_REST_Response([
                'success'   => true,
                'message'   => __('Manual sync complete.', 'calendly-bookings'),
                'last_sync' => [
                    'time'  => $last_sync['time'] ?? null,
                    'count' => $last_sync['count'] ?? 0,
                    'source'=> $last_sync['source'] ?? 'manual',
                ],
            ], 200);
        },
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
});
