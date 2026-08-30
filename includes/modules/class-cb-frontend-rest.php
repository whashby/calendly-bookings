<?php

namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;
use Calendly_Bookings\CB_Constants;

final class CB_Frontend_Rest {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        $ns = 'calendly-bookings/v1';

        // Check user email
        register_rest_route($ns, '/check-user-email', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'cb_check_user_email'],
            'permission_callback' => '__return_true',
        ]);

        // Schedule Hesychia
        register_rest_route($ns, '/schedule-hesychia', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'cb_schedule_hesychia'],
            'permission_callback' => '__return_true',
        ]);

    }

    public static function can_manage(): bool {
        return current_user_can('manage_options');
    }

    /**
     * check entered email.
     */
    public static function cb_check_user_email(WP_REST_Request $request) {
        $email = sanitize_email($request->get_param('email'));
        $user = get_user_by('email', $email);
        return [ 'exists' => $user ? true : false, ];
    }

	private static function error(string $message, int $status = 400) {
        return new \WP_Error('cb_rest_error', $message, ['status' => $status]);
    }

    public static function cb_schedule_hesychia(WP_REST_Request $request) {

        $uuid       = sanitize_text_field($request->get_param('uuid'));
        $product_id = sanitize_text_field($request->get_param('product_id'));
        $api_key    = (string) get_option(CB_Constants::OPT_API_TOKEN, '');
        //$event_uri  = (string) "https://api.calendly.com/scheduled_events/" . $uuid . "/invitees/" . $uuid;
        $first      = sanitize_text_field($request->get_param('first_name'));
        $last       = sanitize_text_field($request->get_param('last_name'));
        $email      = sanitize_email($request->get_param('email'));
        $date       = sanitize_text_field($request->get_param('date'));
        $time       = sanitize_text_field($request->get_param('time'));

        $payload = [
            'event_type' => "https://api.calendly.com/event_types/" . $uuid,
            'start_time' => $date . 'T' . $time . ':00Z',
            'invitee' => [
                'email' => $email,
                'first_name' => $first,
                'last_name' => $last,
                'name'  => trim("$first $last"),
                'timezone'  => wp_timezone_string(),
            ],
            'location' => [
                'kind'     => 'physical',
                'location' => "https://maps.app.goo.gl/7A2Ar17JmFTHzVNe7",
            ],
            'questions_and_answers' => [
                ['question' => 'Order ID s', 'answer' => $product_id, 'position' => 1],
                ['question' => 'Have you experience a sound/vibration session before', 'answer' => 'No', 'position' => 2],
            ],
        ];

        $response = wp_remote_post('https://api.calendly.com/invitees', [
            'body' => wp_json_encode($payload),
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
        ]);

        return $response; // Return the raw response for debugging purposes

        if (is_wp_error($response)) {
            return self::error($response->get_error_message(), 500);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['resource'])) {
            return [ 'success' => true, 'redirect' => home_url('/thank-you') ];
        }

        return self::error('Calendly booking failed', 400);
    }

}
