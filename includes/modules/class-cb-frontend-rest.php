<?php

namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;

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
}
