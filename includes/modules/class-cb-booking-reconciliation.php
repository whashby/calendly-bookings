<?php
declare(strict_types=1);

namespace Calendly_Bookings\Modules;

use Calendly_Bookings\CB_Constants;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the WooCommerce -> Calendly booking lifecycle.
 * The order is never considered Calendly-confirmed until a Calendly webhook
 * has been received and reconciled.
 */
final class CB_Booking_Reconciliation {
    public const ACTION_HOOK = 'cb_booking_process_order';
    public const ACTION_RECONCILE = 'cb_booking_reconcile_webhook';
    public const ACTION_SYNC = 'cb_booking_sync_event';

    public static function init(): void {
        add_action(self::ACTION_HOOK, [__CLASS__, 'process_order'], 10, 1);
        add_action(self::ACTION_RECONCILE, [__CLASS__, 'reconcile_webhook'], 10, 1);
        add_action(self::ACTION_SYNC, [__CLASS__, 'sync_event'], 10, 1);

        add_action('woocommerce_payment_complete', [__CLASS__, 'queue_paid_order'], 20, 1);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'queue_paid_order'], 20, 1);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'queue_paid_order'], 20, 1);
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'queue_free_order'], 20, 3);

        add_action('woocommerce_order_partially_refunded', [__CLASS__, 'handle_refund'], 20, 2);
        add_action('woocommerce_order_fully_refunded', [__CLASS__, 'handle_refund'], 20, 2);
    }

    public static function queue_paid_order(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order || !CB_Checkout::order_has_meeting($order) || (float) $order->get_total() <= 0) {
            return;
        }
        if (!$order->is_paid()) {
            return;
        }
        self::enqueue($order_id, 0, 'paid');
    }

    public static function queue_free_order(int $order_id, array $posted_data = [], object $order = null): void {
        $wc_order = $order instanceof \WC_Order ? $order : wc_get_order($order_id);
        if (!$wc_order || !CB_Checkout::order_has_meeting($wc_order) || (float) $wc_order->get_total() > 0) {
            return;
        }
        self::enqueue($order_id, 0, 'free');
    }

    public static function enqueue(int $order_id, int $delay = 0, string $reason = 'retry'): bool {
        if ($order_id <= 0) {
            return false;
        }

        if (function_exists('as_enqueue_async_action')) {
            $args = ['order_id' => $order_id];
            // Never create duplicate workers for the same order. This is important
            // because WooCommerce can emit several lifecycle events for one order.
            if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::ACTION_HOOK, $args, 'calendly-bookings')) {
                return true;
            }
            $action_id = 0;
            if ($delay > 0 && function_exists('as_schedule_single_action')) {
                $action_id = (int) as_schedule_single_action(time() + $delay, self::ACTION_HOOK, $args, 'calendly-bookings');
            } else {
                $action_id = (int) as_enqueue_async_action(self::ACTION_HOOK, $args, 'calendly-bookings');
            }
            if ($action_id > 0) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $order->update_meta_data('_cb_booking_action_id', $action_id);
                    $order->update_meta_data('_cb_booking_action_reason', sanitize_key($reason));
                    $order->update_meta_data('_cb_booking_queued_at', gmdate('c'));
                    $order->save();
                }
                CB_Logger::debug('[CB Booking] Queued order #' . $order_id . ' as Action Scheduler action #' . $action_id . ' (' . sanitize_key($reason) . ').');
                return true;
            }
            return false;
        }

        // Action Scheduler is supplied by WooCommerce. This fallback is only
        // for installations where it is not loaded yet.
        wp_schedule_single_event(time() + max(1, $delay), 'cb_booking_process_order_fallback', [$order_id]);
        return true;
    }

    public static function process_order(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        CB_Logger::debug('[CB Booking] Processing WooCommerce order #' . $order_id . '.');

        $status = (string) $order->get_meta('_cb_calendly_booking_status', true);
        if ($status === 'confirmed' || $status === 'created' || $status === 'canceled' || $status === 'rescheduled') {
            return;
        }

        if ($status === 'awaiting_webhook' && $order->get_meta('_cb_calendly_invitee_uri', true)) return;

        $is_free = (float) $order->get_total() <= 0;
        if (!$is_free && !$order->is_paid()) {
            self::mark($order, 'pending', 'Awaiting successful WooCommerce payment.');
            return;
        }

        if (!self::acquire_lock($order_id)) {
            return;
        }

        try {
            self::mark($order, 'processing', 'Calendly booking attempt queued.');
            $order->update_meta_data('_cb_calendly_last_attempt_at', gmdate('c'));
            $attempts = absint($order->get_meta('_cb_calendly_attempt_count', true)) + 1;
            $order->update_meta_data('_cb_calendly_attempt_count', $attempts);
            $order->save();

            $event_uuid = self::get_event_uuid($order);
            $start_time = self::get_start_time($order);
            $email = sanitize_email($order->get_billing_email());
            CB_Logger::debug('[CB Booking] Order #' . $order_id . ' booking inputs: event_uuid=' . ($event_uuid ?: 'MISSING') . ', start_time=' . ($start_time ?: 'MISSING') . ', billing_email=' . ($email ? 'present' : 'MISSING') . '.');
            $first = sanitize_text_field($order->get_billing_first_name());
            $last = sanitize_text_field($order->get_billing_last_name());

            if (!$event_uuid || !$start_time || !$email) {
                $missing = [];
                if (!$event_uuid) $missing[] = 'Calendly event type';
                if (!$start_time) $missing[] = 'exact selected start time';
                if (!$email) $missing[] = 'billing email';
                throw new \RuntimeException('Missing ' . implode(', ', $missing) . '. Booking form data was not persisted correctly.');
            }

            $api = CB_API::instance();
            $event_type = $api->get_event_type($event_uuid);
            if (empty($event_type['resource'])) {
                throw new \RuntimeException('Calendly event type could not be resolved.');
            }

            $payload = self::build_payload($order, $event_type['resource'], $event_uuid, $start_time, $first, $last, $email);
            CB_Logger::debug('[CB Booking] Order #' . $order_id . ' Scheduling API payload prepared: questions=' . count((array) ($payload['questions_and_answers'] ?? [])) . ', location=' . (!empty($payload['location']) ? 'present' : 'event-type/default') . '.');
            $order->update_meta_data('_cb_calendly_create_request', wp_json_encode($payload));
            $order->save();
            $response = $api->create_invitee($payload);
            $order->update_meta_data('_cb_calendly_create_http_status', absint($response['status'] ?? 0));
            $order->update_meta_data('_cb_calendly_create_response_body', wp_json_encode($response['body'] ?? $response));
            $order->update_meta_data('_cb_calendly_create_received_at', gmdate('c'));
            $order->save();

            if (!empty($response['error']) || empty($response['resource']['uri']) || empty($response['resource']['event'])) {
                $message = $response['message'] ?? ('Calendly booking failed (HTTP ' . absint($response['status'] ?? 0) . ').');
                throw new \RuntimeException($message);
            }

            $resource = $response['resource'];
            $order->update_meta_data('_cb_calendly_invitee_uri', esc_url_raw((string) ($resource['uri'] ?? '')));
            $order->update_meta_data('_cb_calendly_event_uri', esc_url_raw((string) ($resource['event'] ?? '')));
            $order->update_meta_data('_cb_calendly_cancel_url', esc_url_raw((string) ($resource['cancel_url'] ?? '')));
            $order->update_meta_data('_cb_calendly_reschedule_url', esc_url_raw((string) ($resource['reschedule_url'] ?? '')));
            $order->update_meta_data('_cb_calendly_booking_status', 'awaiting_webhook');
            $order->update_meta_data('_cb_calendly_booking_error', '');
            $order->save();
            $order->add_order_note('Calendly booking created. Waiting for Calendly webhook confirmation.');

            // The response is useful for reconciliation, but the customer-facing
            // confirmation endpoint deliberately does not use it as its source.
            $order->update_meta_data('_cb_calendly_create_response', wp_json_encode($resource));
            $order->save();
        } catch (\Throwable $e) {
            self::mark($order, 'failed', $e->getMessage());
            $attempts = absint($order->get_meta('_cb_calendly_attempt_count', true));
            $delay = self::retry_delay($attempts);
            if ($delay !== null) $delay = max($delay, CB_API::instance()->retry_after());
            if ($delay !== null) {
                self::enqueue($order_id, $delay, 'retry');
                $order->add_order_note(sprintf('Calendly booking failed; automatic retry scheduled in %d seconds: %s', $delay, $e->getMessage()));
            } else {
                $order->add_order_note('Calendly booking permanently failed after retry limit: ' . $e->getMessage());
            }
        } finally {
            self::release_lock($order_id);
        }
    }

    private static function booking_snapshot(\WC_Order $order): array {
        $snapshot = json_decode((string) $order->get_meta('_cb_booking_snapshot', true), true);
        return is_array($snapshot) ? $snapshot : [];
    }

    private static function build_payload(\WC_Order $order, array $event_type, string $event_uuid, string $start_time, string $first, string $last, string $email): array {
        $payload = [
            'event_type' => 'https://api.calendly.com/event_types/' . rawurlencode($event_uuid),
            'start_time' => $start_time,
            'invitee' => [
                'email' => $email,
                'first_name' => $first,
                'last_name' => $last,
                'name' => trim($first . ' ' . $last),
                'timezone' => CB_Customer_Time::valid(CB_Customer_Time::order_timezone($order)->getName()) ?: 'UTC',
            ],
            'tracking' => [
                'utm_source' => 'wordpress',
                'utm_campaign' => 'hierlife',
                'utm_content' => (string) $order->get_id(),
                'utm_medium' => 'woocommerce',
                'utm_term' => null,
                'salesforce_uuid' => null,
            ],
        ];

        $location = self::resolve_location($order, $event_type);
        if ($location) {
            $payload['location'] = $location;
        }

        $payload['questions_and_answers'] = self::build_questions($order, $event_type);
        return $payload;
    }

    private static function resolve_location(\WC_Order $order, array $event_type): ?array {
        $locations = is_array($event_type['locations'] ?? null) ? $event_type['locations'] : [];
        if (!$locations || strtolower((string) ($event_type['pooling_type'] ?? '')) === 'round_robin') {
            return null;
        }

        $snapshot = self::booking_snapshot($order);
        $selected = (string) $order->get_meta('_cb_meeting_location', true);
        if (!$selected) $selected = (string) ($snapshot['cb_meeting_location'] ?? '');
        $detail_index = (string) $order->get_meta('_cb_meeting_location_details', true);
        if (!$detail_index) $detail_index = (string) ($snapshot['cb_meeting_location_details'] ?? '');
        $detail_text = (string) $order->get_meta('_cb_meeting_location_detail_text', true);
        if (!$detail_text) $detail_text = (string) ($snapshot['cb_meeting_location_detail_text'] ?? '');
        foreach ($locations as $index => $location) {
            $kind = strtolower((string) ($location['kind'] ?? $location['type'] ?? ''));
            $key = 'loc_' . $index . '_' . substr(hash('sha256', wp_json_encode($location)), 0, 12);
            if ($selected && hash_equals($key, $selected)) {
                return self::scheduling_location($location, $detail_text);
            }
            if ($detail_index !== '' && (string)$index === $detail_index) {
                return self::scheduling_location($location, $detail_text);
            }
        }
        if (count($locations) === 1) return self::scheduling_location($locations[0], $detail_text);
        // Legacy compatibility with the old 1=remote / 2=physical selector.
        foreach ($locations as $location) {
            $kind = strtolower((string) ($location['kind'] ?? $location['type'] ?? ''));
            if (($selected === '1' && in_array($kind, ['zoom','zoom_conference','google_conference','microsoft_teams','phone','outbound_call'], true)) || ($selected === '2' && in_array($kind, ['physical','custom'], true))) return self::scheduling_location($location, $detail_text);
        }
        throw new \RuntimeException('The selected Calendly location no longer matches this event type.');
    }

    private static function scheduling_location(array $location, string $detail): array {
        $kind = (string) ($location['kind'] ?? $location['type'] ?? '');
        if ($kind === '') throw new \RuntimeException('Calendly location kind is missing.');
        $result = ['kind' => $kind];
        if (in_array($kind, ['ask_invitee','outbound_call'], true)) {
            if ($detail === '') throw new \RuntimeException('Please provide the required Calendly location details.');
            $result['location'] = $detail;
        } elseif (!empty($location['location'])) {
            $result['location'] = (string) $location['location'];
        }
        return $result;
    }

    private static function build_questions(\WC_Order $order, array $event_type): array {
        $configured = is_array($event_type['custom_questions'] ?? null) ? $event_type['custom_questions'] : [];
        $stored = json_decode((string) $order->get_meta('_cb_calendly_answers', true), true);
        if (!is_array($stored)) {
            $snapshot = self::booking_snapshot($order);
            $stored = is_array($snapshot['cb_calendly_answers'] ?? null) ? $snapshot['cb_calendly_answers'] : [];
        }
        $answers = [];
        foreach ($configured as $position => $question) {
            if (!is_array($question) || empty($question['enabled'])) continue;
            $name = (string) ($question['name'] ?? '');
            if (trim($name) === '') continue;
            if (preg_match('/^order\s*id(?:\b|\s*\()/i', trim($name))) {
                $answers[] = ['question' => $name, 'answer' => (string) $order->get_id(), 'position' => absint($question['position'] ?? $position)];
                continue;
            }
            $key = CB_Frontend::question_key($question);
            $value = $stored[$key] ?? '';
            if (is_array($value)) $value = implode(', ', array_map('sanitize_text_field', $value));
            else $value = sanitize_textarea_field((string) $value);
            if ($value === '') { $fallback = self::legacy_question_answer($order, $name); if ($fallback !== '') $value = $fallback; }
            if (is_array($stored[$key] ?? null)) $value = implode(', ', array_map('sanitize_text_field', (array)$stored[$key]));
            $other = isset($stored[$key . '_other']) ? sanitize_text_field((string)$stored[$key . '_other']) : '';
            if ($other !== '') $value = trim((string)$value . ($value !== '' ? '; ' : '') . 'Other: ' . $other);
            if ($value === '' && !empty($question['required'])) throw new \RuntimeException('Required Calendly question is missing: ' . $name);
            $answers[] = ['question'=>$name,'answer'=>(string)$value,'position'=>absint($question['position'] ?? $position)];
        }
        return $answers;
    }

    private static function legacy_question_answer(\WC_Order $order, string $name): string {
        $map = [
            'Please let me know how you became aware of HIER Life.'=>(string)$order->get_meta('_cb_hier_intro', true),
            'Please share anything that will help prepare for our meeting.'=>(string)$order->get_meta('_cb_prep_notes', true),
            'Are you new to formal practice of meditation?'=>(string)$order->get_meta('_cb_new_practice', true),
            'If you have practiced before, what methods have you explored? If none respond - N/A'=>(string)$order->get_meta('_cb_methods', true),
            'Do you have any familiarity with the following?'=>(string)$order->get_meta('_cb_familiarity', true),
            'Do you have any familiarity with the following? Whether you have employed them or not'=>(string)$order->get_meta('_cb_familiarity', true),
            'Other practice specified'=>(string)$order->get_meta('_cb_other_text', true),
            'Since your previous session is there any experience or thought that you would want to raise in the coming session?'=>(string)$order->get_meta('_cb_experience', true),
            'Write 6 questions you would want answers for during the session.'=>(string)$order->get_meta('_cb_qhht_questions', true),
        ];
        return isset($map[$name]) ? sanitize_textarea_field($map[$name]) : '';
    }

    public static function reconcile_webhook(array $webhook): void {
        $raw_payload = is_array($webhook['payload'] ?? null) ? $webhook['payload'] : [];
        $invitee = is_array($raw_payload['invitee'] ?? null) ? $raw_payload['invitee'] : $raw_payload;
        $event = is_array($raw_payload['event'] ?? null) ? $raw_payload['event'] : [];
        if (!$event && is_array($raw_payload['scheduled_event'] ?? null)) $event = $raw_payload['scheduled_event'];
        if (!$event && is_array($invitee['scheduled_event'] ?? null)) $event = $invitee['scheduled_event'];
        $event_name = (string) ($webhook['event'] ?? '');
        $invitee_uri = (string) ($invitee['uri'] ?? '');
        $event_uri = (string) ($event['uri'] ?? ($invitee['event'] ?? ''));

        if (!$invitee_uri && !$event_uri) return;

        global $wpdb;
        $table = $wpdb->prefix . 'cb_scheduled_events';
        $invitee_table = $wpdb->prefix . 'cb_scheduled_event_invitees';

        $order_id = self::find_order_id($invitee, $event, $invitee_uri, $event_uri);
        $event_uuid = self::uuid_from_uri($event_uri);
        $event_type_uri = (string) ($event['event_type'] ?? $raw_payload['event_type']['uri'] ?? '');
        if (is_array($event_type_uri)) $event_type_uri = (string) ($event_type_uri['uri'] ?? '');
        $event_type_uuid = self::uuid_from_uri($event_type_uri);
        $event_type_id = $event_type_uuid ? absint($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}cb_event_types WHERE uuid=%s LIMIT 1", $event_type_uuid))) : 0;
        $invitee_uuid = self::uuid_from_uri($invitee_uri);
        $start = self::iso_to_mysql($event['start_time'] ?? $invitee['start_time'] ?? '');
        $end = self::iso_to_mysql($event['end_time'] ?? $invitee['end_time'] ?? '');

        $existing = $event_uuid ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE uuid=%s", $event_uuid), ARRAY_A) : null;
        if (!$existing && $event_uri) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE uri=%s", $event_uri), ARRAY_A);
        }

        if ($event_uuid && $start && $event_type_id) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (uuid, order_id, event_type_id, name, start_time, end_time, status, uri, reschedule_url, cancel_url, payload, webhook_event, webhook_received_at, created_ts, updated_ts)
                 VALUES (%s,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE order_id=VALUES(order_id), name=VALUES(name), start_time=VALUES(start_time), end_time=VALUES(end_time), status=VALUES(status), uri=VALUES(uri), reschedule_url=VALUES(reschedule_url), cancel_url=VALUES(cancel_url), payload=VALUES(payload), webhook_event=VALUES(webhook_event), webhook_received_at=UTC_TIMESTAMP(), updated_ts=UTC_TIMESTAMP()",
                $event_uuid,
                $order_id ? (string) $order_id : null,
                $event_type_id,
                sanitize_text_field((string) ($event['name'] ?? '')),
                $start,
                $end ?: $start,
                $event_name === 'invitee.canceled' ? 'canceled' : 'active',
                esc_url_raw($event_uri),
                esc_url_raw((string) ($invitee['reschedule_url'] ?? '')),
                esc_url_raw((string) ($invitee['cancel_url'] ?? '')),
                wp_json_encode($webhook),
                $event_name
            ));
        }

        if ($invitee_uuid && $event_uuid) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$invitee_table} (scheduled_event_uuid, uuid, name, email, status, answers, payload, created_ts, updated_ts)
                 VALUES (%s,%s,%s,%s,%s,%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE scheduled_event_uuid=VALUES(scheduled_event_uuid), name=VALUES(name), email=VALUES(email), status=VALUES(status), answers=VALUES(answers), payload=VALUES(payload), updated_ts=UTC_TIMESTAMP()",
                $event_uuid,
                $invitee_uuid,
                sanitize_text_field((string) ($invitee['name'] ?? '')),
                sanitize_email((string) ($invitee['email'] ?? '')),
                $event_name === 'invitee.canceled' ? 'canceled' : 'active',
                wp_json_encode($invitee['questions_and_answers'] ?? []),
                wp_json_encode($webhook)
            ));
        }

        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_cb_calendly_webhook_received_at', gmdate('c'));
                $order->update_meta_data('_cb_calendly_webhook_event', $event_name);
                $order->update_meta_data('_cb_calendly_webhook_payload', wp_json_encode($webhook));
                if ($invitee_uri) $order->update_meta_data('_cb_calendly_invitee_uri', esc_url_raw($invitee_uri));
                if ($event_uri) $order->update_meta_data('_cb_calendly_event_uri', esc_url_raw($event_uri));
                if (!empty($invitee['cancel_url'])) $order->update_meta_data('_cb_calendly_cancel_url', esc_url_raw($invitee['cancel_url']));
                if (!empty($invitee['reschedule_url'])) $order->update_meta_data('_cb_calendly_reschedule_url', esc_url_raw($invitee['reschedule_url']));

                $status = match ($event_name) {
                    'invitee.canceled' => 'canceled',
                    default => 'confirmed',
                };
                $order->update_meta_data('_cb_calendly_booking_status', $status);
                $order->update_meta_data('_cb_calendly_booking_error', '');
                $order->save();
                $order->add_order_note('Calendly webhook reconciled: ' . $event_name . '.');

                // Send verified-webhook notifications once. A Calendly reschedule
                // emits both canceled and created; suppress the cancellation email
                // for the old invitee when Calendly marks it as rescheduled.
                if ($event_name === 'invitee.created') {
                    CB_Email::send_once($order, CB_Email::TEMPLATE_CONFIRMED, $webhook);
                    CB_Email::send_once($order, CB_Email::TEMPLATE_ADMIN, $webhook);
                } elseif ($event_name === 'invitee.canceled') {
                    $is_rescheduled = !empty($invitee['rescheduled']);
                    if (!$is_rescheduled) {
                        CB_Email::send_once($order, CB_Email::TEMPLATE_CANCELED, $webhook);
                    }
                    CB_Email::send_once($order, CB_Email::TEMPLATE_ADMIN, $webhook);
                }
            }
        }
    }

    public static function sync_event(string $event_uri): void {
        if (!$event_uri) return;
        $api = CB_API::instance();
        $event = $api->get_resource($event_uri);
        if (!empty($event['resource'])) {
            // Keep reconciliation driven by webhook data; this job is for recovery.
            self::reconcile_webhook(['event' => 'invitee.created', 'payload' => ['event' => $event['resource']]]);
        }
    }

    private static function find_order_id(array $invitee, array $event, string $invitee_uri, string $event_uri): int {
        global $wpdb;
        $order = 0;
        if (!$order) {
            foreach ((array) ($invitee['tracking'] ?? []) as $key => $value) {
                if (strtolower((string) $key) === 'utm_content') {
                    $candidate = absint($value);
                    if ($candidate && wc_get_order($candidate)) { $order = $candidate; break; }
                }
            }
        }
        if (!$order && $invitee_uri) {
            $order = absint($wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_cb_calendly_invitee_uri' AND meta_value=%s LIMIT 1", $invitee_uri)));
        }
        if (!$order && $event_uri) {
            $order = absint($wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_cb_calendly_event_uri' AND meta_value=%s LIMIT 1", $event_uri)));
        }
        if (!$order && $invitee_uri) {
            $order = absint($wpdb->get_var($wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}cb_scheduled_events WHERE payload LIKE %s LIMIT 1", '%' . $wpdb->esc_like($invitee_uri) . '%')));
        }
        if (!$order && $event_uri) {
            $order = absint($wpdb->get_var($wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}cb_scheduled_events WHERE uri=%s LIMIT 1", $event_uri)));
        }
        return $order;
    }

    private static function get_event_uuid(\WC_Order $order): string {
        $stored = (string) $order->get_meta('_cb_event_uuid', true);
        if ($stored) return $stored;

        $snapshot = json_decode((string) $order->get_meta('_cb_booking_snapshot', true), true);
        if (is_array($snapshot) && !empty($snapshot['cb_event_uuid'])) {
            return sanitize_text_field((string) $snapshot['cb_event_uuid']);
        }

        foreach ($order->get_items() as $item) {
            $item_uuid = (string) $item->get_meta('_cb_booking_event_uuid', true);
            if ($item_uuid) return $item_uuid;
            $product = $item->get_product();
            if (!$product) continue;
            $uuid = (string) $product->get_meta('_cb_event_uuid', true);
            if ($uuid) return $uuid;
            $parent = $product->get_parent_id();
            if ($parent) {
                $uuid = (string) get_post_meta($parent, '_cb_event_uuid', true);
                if ($uuid) return $uuid;
            }
        }
        return '';
    }

    private static function get_start_time(\WC_Order $order): string {
        $raw = (string) $order->get_meta('_cb_meeting_start_iso', true);
        if (!$raw) {
            $snapshot = json_decode((string) $order->get_meta('_cb_booking_snapshot', true), true);
            if (is_array($snapshot)) $raw = (string) ($snapshot['cb_meeting_start_iso'] ?? '');
        }
        if (!$raw) {
            foreach ($order->get_items() as $item) {
                $raw = (string) $item->get_meta('_cb_booking_start_iso', true);
                if ($raw) break;
            }
        }
        if (!$raw) $raw = (string) $order->get_meta('_cb_meeting_time', true);
        if (!$raw) return '';
        try {
            $dt = new \DateTimeImmutable($raw);
        } catch (\Throwable $e) {
            return '';
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function acquire_lock(int $order_id): bool {
        $key = 'cb_booking_lock_' . $order_id;
        if (get_transient($key)) return false;
        set_transient($key, wp_generate_uuid4(), 120);
        return true;
    }

    private static function release_lock(int $order_id): void {
        delete_transient('cb_booking_lock_' . $order_id);
    }

    private static function mark(\WC_Order $order, string $status, string $error = ''): void {
        $order->update_meta_data('_cb_calendly_booking_status', $status);
        $order->update_meta_data('_cb_calendly_booking_error', sanitize_text_field($error));
        $order->save();
    }

    private static function retry_delay(int $attempt): ?int {
        return match ($attempt) {
            1 => 0,
            2 => 30,
            3 => 120,
            4 => 600,
            5 => 1800,
            default => null,
        };
    }

    private static function uuid_from_uri(string $uri): string {
        if (preg_match('~/([0-9a-fA-F-]{20,})$~', $uri, $m)) return $m[1];
        return '';
    }

    private static function iso_to_mysql(string $iso): string {
        if (!$iso) return '';
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function handle_refund(int $order_id, int $refund_id = 0): void {
        $order = wc_get_order($order_id);
        if (!$order) return;
        $mode = (string) get_option('cb_refund_cancel_calendly', 'no');
        if ($mode !== 'yes') return;
        // Cancellation is deliberately left to the existing admin/API layer unless
        // explicitly enabled; queueing keeps refund handling asynchronous.
        $order->add_order_note('Calendly cancellation requested by configured refund policy.');
    }
}
