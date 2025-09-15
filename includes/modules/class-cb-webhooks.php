<?php
// includes/modules/class-cb-webhooks.php
namespace Calendly_Bookings\Modules;

use Calendly_Bookings\CB_Constants;

if (!defined('ABSPATH')) exit;

final class CB_Webhooks {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_endpoint']);
    }

    public static function register_endpoint(): void {
        register_rest_route('calendly-bookings/v1', '/webhook', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'receive'],
            'permission_callback' => '__return_true', // Auth via signature only
        ]);
    }

    public static function receive(\WP_REST_Request $req): \WP_REST_Response {
        $secret = (string) get_option(CB_Constants::OPT_WEBHOOK_SECRET, '');
        $body   = $req->get_body();
        $headers = array_change_key_case($req->get_headers(), CASE_LOWER);

        $sig = $headers['calendly-webhook-signature'][0] ?? ($headers['x-calendly-signature'][0] ?? '');
        if (!$secret || !self::verify_signature($body, $sig, $secret)) {
            CB_Logger::log('webhook_reject', ['topic' => 'unknown', 'error' => 'invalid_signature']);
            return new \WP_REST_Response(['ok' => false], 401);
        }

        $json = json_decode($body, true) ?: [];
        $event = (string) ($json['event'] ?? '');
        CB_Logger::log('webhook_received', ['topic' => $event]);

        // TODO: handle events (invitee.created, invitee.canceled, event_type.updated, etc.)
        // Keep minimal here to avoid side effects by default.

        return new \WP_REST_Response(['ok' => true], 200);
    }

    // Supports "t=timestamp,v1=signature" and raw HMAC formats
    private static function verify_signature(string $payload, string $header, string $secret): bool {
        $provided = '';
        if (strpos($header, 'v1=') !== false) {
            // Format: t=...,v1=hex
            $parts = [];
            foreach (explode(',', $header) as $kv) {
                [$k, $v] = array_map('trim', explode('=', $kv, 2) + ['', '']);
                $parts[$k] = $v;
            }
            $provided = $parts['v1'] ?? '';
            // If Calendly includes timestamp in base string, adapt here. We default to raw payload HMAC for widest compatibility.
        } else {
            $provided = trim($header);
        }

        if ($provided === '') return false;
        $calc = hash_hmac('sha256', $payload, $secret);
        return hash_equals($calc, $provided);
    }

    public static function register_webhook(string $url, string $events, string $secret): array {
        $api = new CB_API();
        $payload = [
            'url'    => $url,
            'events' => explode(',', $events), // e.g. "invitee.created,invitee.canceled"
            'scope'  => 'organization',
            'organization' => 'https://api.calendly.com/organizations/' . $api->get_org_uuid(),
            'signing_key'  => $secret,
        ];

        $res = wp_remote_post('https://api.calendly.com/webhook_subscriptions', [
            'headers' => [
                'Authorization' => 'Bearer ' . get_option(CB_Constants::OPT_API_TOKEN, ''),
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($res)) {
            return ['success' => false, 'message' => $res->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);

        return ($code >= 200 && $code < 300)
            ? ['success' => true, 'data' => $body]
            : ['success' => false, 'message' => $body['message'] ?? 'Unknown error'];
    }
}
