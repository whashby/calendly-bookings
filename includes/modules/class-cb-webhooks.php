<?php
declare(strict_types=1);

namespace Calendly_Bookings\Modules;

use Calendly_Bookings\CB_Constants;

if (!defined('ABSPATH')) exit;

final class CB_Webhooks {
    private const MAX_AGE = 300;

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_endpoint']);
    }

    public static function register_endpoint(): void {
        register_rest_route('calendly-bookings/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'receive'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function receive(\WP_REST_Request $req): \WP_REST_Response {
        $secret = (string) get_option(CB_Constants::OPT_WEBHOOK_SECRET, '');
        $body = $req->get_body();
        $signature = (string) $req->get_header('Calendly-Webhook-Signature');

        if (!$secret || !self::verify_signature($body, $signature, $secret)) {
            return new \WP_REST_Response(['ok' => false, 'message' => 'Invalid signature.'], 401);
        }

        $hash = hash('sha256', $signature . '|' . $body);
        $replay_key = 'cb_webhook_replay_' . $hash;
        if (get_transient($replay_key)) {
            return new \WP_REST_Response(['ok' => true, 'duplicate' => true], 200);
        }

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['event']) || !isset($json['payload'])) {
            return new \WP_REST_Response(['ok' => false, 'message' => 'Malformed webhook payload.'], 400);
        }

        $event = sanitize_text_field((string) $json['event']);
        $allowed = [
            'invitee.created',
            'invitee.canceled',
            'invitee_no_show.created',
            'invitee_no_show.deleted',
            'event_type.created',
            'event_type.updated',
            'event_type.deleted',
        ];
        if (!in_array($event, $allowed, true)) {
            return new \WP_REST_Response(['ok' => true, 'ignored' => true], 200);
        }

        $job = [
            'event' => $event,
            'payload' => is_array($json['payload']) ? $json['payload'] : [],
            'sent_at' => gmdate('c'),
        ];

        if (function_exists('as_enqueue_async_action')) {
            $action_id = as_enqueue_async_action(CB_Booking_Reconciliation::ACTION_RECONCILE, ['webhook' => $job], 'calendly-bookings');
            if (!$action_id) return new \WP_REST_Response(['ok' => false, 'message' => 'Unable to queue webhook.'], 503);
        } else {
            CB_Booking_Reconciliation::reconcile_webhook($job);
        }

        set_transient($replay_key, 1, self::MAX_AGE);
        return new \WP_REST_Response(['ok' => true, 'queued' => true], 200);
    }

    private static function verify_signature(string $body, string $header, string $secret): bool {
        if ($header === '') return false;
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, '=')) continue;
            [$key, $value] = array_map('trim', explode('=', $pair, 2));
            $parts[$key] = $value;
        }

        $timestamp = absint($parts['t'] ?? 0);
        $provided = (string) ($parts['v1'] ?? '');
        if (!$timestamp || !$provided) return false;
        if (abs(time() - $timestamp) > self::MAX_AGE) return false;

        $signed_payload = $timestamp . '.' . $body;
        $calculated = hash_hmac('sha256', $signed_payload, $secret);
        return hash_equals($calculated, $provided);
    }

    public static function register_webhook(string $url, string $events, string $secret): array {
        $api = CB_API::instance();
        $me = $api->get_resource('https://api.calendly.com/users/me');
        $user = $me['resource'] ?? [];
        $org = (string) ($user['current_organization'] ?? '');
        if (!$org) return ['success' => false, 'message' => 'Unable to determine Calendly organization.'];

        $payload = [
            'url' => esc_url_raw($url),
            'events' => array_values(array_filter(array_map('trim', explode(',', $events)))),
            'scope' => 'organization',
            'organization' => $org,
            'signing_key' => $secret,
        ];
        $response = wp_remote_post(CB_Constants::CB_API_BASE_URL . '/webhook_subscriptions', [
            'headers' => [
                'Authorization' => 'Bearer ' . get_option(CB_Constants::OPT_API_TOKEN, ''),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) return ['success' => false, 'message' => $response->get_error_message()];
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $code >= 200 && $code < 300
            ? ['success' => true, 'data' => $body]
            : ['success' => false, 'message' => (string) ($body['message'] ?? 'Webhook registration failed.')];
    }
}
