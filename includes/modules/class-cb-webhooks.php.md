# Copy of class-cb-webhooks.php

```php
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
		$secret  = (string) get_option(CB_Constants::OPT_WEBHOOK_SECRET, '');
		$body    = $req->get_body();
		$headers = array_change_key_case($req->get_headers(), CASE_LOWER);

		$sig = $headers['calendly-webhook-signature'][0] ?? ($headers['x-calendly-signature'][0] ?? '');
		if (!$secret || !self::verify_signature($body, $sig, $secret)) {
			CB_Logger::log('webhook_reject', ['topic' => 'unknown', 'error' => 'invalid_signature']);
			CB_Audit_Log::log('reject', 'webhook', '', ['error' => 'invalid_signature'], 'warning');
			return new \WP_REST_Response(['ok' => false], 401);
		}

		$json  = json_decode($body, true) ?: [];
		$event = (string) ($json['event'] ?? '');
		$payload = $json['payload'] ?? [];

		CB_Logger::log('webhook_received', ['topic' => $event]);
		CB_Audit_Log::log('received', 'webhook', $event, $payload, 'info');

		switch ($event) {
			case 'invitee.created':
				$email = $payload['invitee']['email'] ?? ''; 
				CB_Logger::log('invitee_created', ['email' => $email]); 
				CB_Audit_Log::log('invitee_created', 'webhook', $email, $payload, 'info'); 
				
				// --- Custom: trace order number from answer_1 --- 
				global $wpdb; if (function_exists('get_upcoming_events')) { 
					get_upcoming_events(); // refresh events table 
				} 
				
				$start_raw = $payload['event']['start_time'] ?? ''; 
				$order_number = $payload['questions_and_answers'][0]['answer'] ?? ''; // answer_1 
				
				if ($start_raw && $order_number) { 
					$start_time = date('Y-m-d H:i:s', strtotime($start_raw)); 
					$wpdb->update( 
						"{$wpdb->prefix}cb_scheduled_events", 
						['order_id' => intval($order_number)], 
						['start_time' => $start_time], 
						['%d'], 
						['%s'] 
					); 
					
					CB_Logger::log('order_linked', ['start_time' => $start_time, 'order_id' => $order_number]); 
					CB_Audit_Log::log('order_linked', 'webhook', $start_time, ['order_id' => $order_number], 'info'); 
				}
				break;

			case 'invitee.canceled':
				$email = $payload['invitee']['email'] ?? '';
				CB_Logger::log('invitee_canceled', ['email' => $email]);
				CB_Audit_Log::log('invitee_canceled', 'webhook', $email, $payload, 'info');
				break;

			case 'event_type.created':
				$uuid = $payload['event_type']['uuid'] ?? '';
				CB_Logger::log('event_type_created', ['uuid' => $uuid]);
				CB_Audit_Log::log('event_type_created', 'webhook', $uuid, $payload, 'info');
				break;

			case 'event_type.updated':
				$uuid = $payload['event_type']['uuid'] ?? '';
				CB_Logger::log('event_type_updated', ['uuid' => $uuid]);
				CB_Audit_Log::log('event_type_updated', 'webhook', $uuid, $payload, 'info');
				break;

			case 'event_type.deleted':
				$uuid = $payload['event_type']['uuid'] ?? '';
				CB_Logger::log('event_type_deleted', ['uuid' => $uuid]);
				CB_Audit_Log::log('event_type_deleted', 'webhook', $uuid, $payload, 'info');
				break;

			default:
				CB_Logger::log('webhook_unhandled', ['topic' => $event]);
				CB_Audit_Log::log('unhandled', 'webhook', $event, $payload, 'warning');
				break;
		}

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
			CB_Audit_Log::log('register_failed', 'webhook', $url, ['error' => $res->get_error_message()], 'error');
			return ['success' => false, 'message' => $res->get_error_message()];
		}

		$code = wp_remote_retrieve_response_code($res);
		$body = json_decode(wp_remote_retrieve_body($res), true);

		if ($code >= 200 && $code < 300) {
			CB_Audit_Log::log('register_success', 'webhook', $url, ['events' => $events], 'info');
			return ['success' => true, 'data' => $body];
		} else {
			CB_Audit_Log::log('register_failed', 'webhook', $url, ['response' => $body], 'error');
			return ['success' => false, 'message' => $body['message'] ?? 'Unknown error'];
		}

    }
}

```
