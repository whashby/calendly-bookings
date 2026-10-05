<?php

namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;
use Calendly_Bookings\Modules\CB_Scheduled_Events;
use Calendly_Bookings\Utils\CB_Encryption;
use Calendly_Bookings\Utils\CB_Mail;
use Calendly_Bookings\Utils\CB_Timezone_Converter;
use WC_Order_Query;

final class CB_Admin_Ajax {

    /** Require an authenticated administrator request for admin-only AJAX. */
    private static function require_admin(string $capability = 'manage_options'): void {
        if (!current_user_can($capability)) {
            wp_send_json_error(['message' => __('Unauthorized.', 'calendly-bookings')], 403);
        }
        check_ajax_referer('cb_admin_nonce', 'nonce');
    }

    /**
     * Initialize AJAX handlers for admin actions.
     */
    public static function init(): void {

        add_action('wp_ajax_calendly_bookings_update_scheduled_event',[__CLASS__, 'update_scheduled_event']);
        add_action('wp_ajax_calendly_bookings_bulk_update_scheduled_events',[__CLASS__, 'bulk_update_scheduled_events']);
        add_action('wp_ajax_calendly_bookings_add_admin_notes',[__CLASS__, 'add_admin_notes']);
        // Maintenance actions
        add_action('wp_ajax_cb_maintenance_action',[__CLASS__, 'maintenance_action']);
        add_action('wp_ajax_cb_create_walk_in',[__CLASS__, 'create_walk_in']);



        add_action('wp_ajax_cb_test_connection',[__CLASS__, 'test_connection']);
        add_action('wp_ajax_cb_save_credentials',[__CLASS__, 'save_credentials']);
        add_action('wp_ajax_cb_validate_license', [__CLASS__, 'validate_license_ajax']);
        add_action('wp_ajax_cb_dismiss_update_notice', [__CLASS__, 'dismiss_update_notice']);

        add_action('wp_ajax_cb_schedule_individual_sync', [__CLASS__, 'schedule_individual_sync']);
        add_action('wp_ajax_cb_clear_individual_sync', [__CLASS__, 'clear_individual_sync']);
        add_action('wp_ajax_cb_clear_individual_crons', [__CLASS__, 'clear_individual_crons']);
        add_action('wp_ajax_cb_schedule_master_sync', [__CLASS__, 'schedule_master_sync']);
        add_action('wp_ajax_cb_clear_master_sync', [__CLASS__, 'clear_master_sync']);
        
        
        add_action('wp_ajax_cb_save_email_templates', [__CLASS__, 'save_email_templates']);
        add_action('wp_ajax_cb_test_email', [__CLASS__, 'test_email']);
        add_action('wp_ajax_cb_preview_email', [__CLASS__, 'preview_email']);

        add_action('wp_ajax_cb_save_report_settings', [__CLASS__, 'save_report_settings']);
        add_action('wp_ajax_cb_get_reports', [__CLASS__, 'get_reports']);
        add_action('wp_ajax_cb_process_report', [__CLASS__, 'process_report']);
        add_action('wp_ajax_cb_generate_report', [__CLASS__, 'generate_report']);
        add_action('wp_ajax_cb_generate_scheduled_report', [__CLASS__, 'generate_scheduled_report']);
        add_action('wp_ajax_cb_preview_report', [__CLASS__, 'preview_report']);
        add_action('wp_ajax_cb_delete_report', [__CLASS__, 'delete_report']);
        add_action('wp_ajax_cb_bulk_delete_reports', [__CLASS__, 'bulk_delete_reports']);
        add_action('wp_ajax_cb_download_report', [__CLASS__, 'download_report']);
        add_action('wp_ajax_cb_bulk_download_reports', [__CLASS__, 'bulk_download_reports']);

        add_action('wp_ajax_cb_get_active_crons', [__CLASS__, 'get_active_crons']);
        add_action('wp_ajax_cb_sync_scheduled_events_now', [__CLASS__, 'sync_scheduled_events_now']);
        add_action('wp_ajax_cb_get_event_availability', [__CLASS__, 'get_event_availability']);
        add_action('wp_ajax_nopriv_cb_get_event_availability', [__CLASS__, 'get_event_availability']);

        // Schedule cron hook
        // Scheduled reports are handled by CB_Reports on every request.
    }

    /**
     * Handle single scheduled event update (notes, completed, etc.)
     */
    public static function update_scheduled_event(): void {
        self::require_admin();
        $uuid  = sanitize_text_field($_POST['uuid'] ?? '');
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
        $notes = isset($_POST['notes']) && is_array($_POST['notes']) ? wp_unslash($_POST['notes']) : [];
        $data  = isset($_POST['data']) ? (array) $_POST['data'] : [];
    
        if (!$uuid) {
            wp_send_json_error(['message' => 'Missing uuid']);
        }
    
        if (!empty($status)) {
            $data['status'] = $status;
        }

        // Flatten notes array into a single string or structured JSON
        if (!empty($notes)) {
            global $wpdb;
            $existing = json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT notes FROM {$wpdb->prefix}cb_scheduled_events WHERE uuid=%s", $uuid)), true);
            $clean = array_map('sanitize_textarea_field', array_intersect_key($notes, array_flip(['discussed', 'guidance', 'follow_up', 'admin'])));
            $data['notes'] = wp_json_encode(array_merge(is_array($existing) ? $existing : [], $clean));
        }
    
        $updated = CB_Scheduled_Events::instance()->update_event($uuid, $data);
    
        if ($updated) {
            wp_send_json_success([
                'uuid'    => $uuid,
                'updated' => $data
            ]);
        }
    
        wp_send_json_error(['message' => 'Update failed']);
    }
    
    /**
     * Handle bulk update of multiple scheduled events
     */
    public static function bulk_update_scheduled_events(): void {
        self::require_admin();
        $uuids  = isset($_POST['uuids']) ? explode(',', sanitize_text_field($_POST['uuids'])) : [];
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
    
        // Define allowed statuses
        $allowed_statuses = ['active', 'scheduled', 'rescheduled', 'canceled', 'completed', 'pending'];
    
        if (empty($uuids) || empty($status)) {
            wp_send_json_error(['message' => 'No UUIDs or status provided']);
        }
    
        if (!in_array($status, $allowed_statuses, true)) {
            wp_send_json_error(['message' => 'Invalid status value']);
        }
    
        $service = CB_Scheduled_Events::instance();
        $results = [];
    
        foreach ($uuids as $uuid) {
            $uuid = trim($uuid);
            if (!$uuid) {
                $results[$uuid] = ['success' => false, 'error' => 'Invalid UUID'];
                continue;
            }
    
            $normalized_status = $status === 'scheduled' ? 'active' : $status;
            $updated = $service->update_event($uuid, ['status' => $normalized_status]);
    
            $results[$uuid] = [
                'success' => (bool) $updated,
                'error'   => $updated ? null : 'Update failed'
            ];
        }
    
        wp_send_json_success(['results' => $results]);
    }
    
    /**
     * Handle adding/updating admin notes for a scheduled event
     */
    public static function add_admin_notes(): void {
        self::require_admin();
        $uuid  = sanitize_text_field($_POST['uuid'] ?? '');
        $notes = isset($_POST['notes']) && is_array($_POST['notes']) ? wp_unslash($_POST['notes']) : [];
        
    
        if (!$uuid) {
            wp_send_json_error(['message' => 'Missing UUID']);
        }

        global $wpdb;
        $data = (array) json_decode( 
            $wpdb->get_var($wpdb->prepare(
                "SELECT notes FROM {$wpdb->prefix}cb_scheduled_events WHERE uuid = %s",
                $uuid
            ))
        );
        

        $data['admin'] = sanitize_textarea_field((string) ($notes['admin'] ?? ''));


        $service = CB_Scheduled_Events::instance();
        $updated = $service->update_event($uuid, ['notes' => json_encode($data)]);

        $results[$uuid] = [
            'success' => (bool) $updated,
            'error'   => $updated ? null : 'Update failed'
        ];
    
        wp_send_json_success(['results' => $results]);
    }

    /**
     * Handle various maintenance actions triggered from the admin interface
     */
    public static function maintenance_action(): void {
        self::require_admin();
        $action = sanitize_text_field($_POST['subaction'] ?? '');

        switch ($action) {
            case 'clear_cache':
                $result = CB_Maintenance::instance()->clear_cache();
                break;
            case 'rebuild_links':
                $result = CB_Maintenance::instance()->rebuild_links();
                break;
            case 'update_created_ts':
                $result = CB_Maintenance::instance()->update_created_ts();
                break;
            case 'refresh_urls':
                $result = CB_Maintenance::instance()->refresh_urls();
                break;
            case 'backfill_order_ids':
                $result = CB_Maintenance::instance()->backfill_order_ids();
                break;
            case 'normalize_statuses':
                $result = CB_Maintenance::instance()->normalize_statuses();
                break;
            default:
                wp_send_json_error(['message' => 'Unknown maintenance action']);
        }

        wp_send_json_success(['message' => 'Action completed', 'result' => $result]);
    }

    /**
     * Handle walk-in creation from admin interface
     */
    public static function create_walk_in() {
        self::require_admin();
        // Decode JSON payload into array of name/value pairs
        $decoded = json_decode(wp_unslash($_POST['data'] ?? ''), true);

        $data = [];
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (isset($item['name'], $item['value'])) {
                    // Handle nested arrays (like notes) separately
                    if (is_array($item['value'])) {
                        $data[$item['name']] = array_map('sanitize_text_field', $item['value']);
                    } else {
                        $data[$item['name']] = sanitize_text_field($item['value']);
                    }
                }
            }
        }

        // Now safely access values
        $firstname = $data['firstname'] ?? '';
        $lastname = $data['lastname'] ?? '';
        $name = trim($firstname . ' ' . $lastname) ?? '';
        $email = sanitize_email($data['email']) ?? '';
        $initial_session = $data['initial_session'] ?? '';
        $initial_session_id = $data['initial_session_id'] ?? '';
        $initial_session_uuid = $data['initial_session_uuid'] ?? '';
        $initial_product_id = $data['initial_session_product_id'] ?? '';
        $start_time = CB_Timezone_Converter::to_iso_time($data['start_time']) ?? '';
        $notes = wp_json_encode($data['notes'] ?? []);
        $location_id = $data['location'] ?? '';
        $followup_session = $data['followup_session'] ?? '';
        $followup_date = $data['followup_date'] ?? '';
        $followup_time = $data['followup_time'] ?? '';
        $followup_product_id = $data['followup_session_product_id'] ?? '';

        // 1. Create or update WP user
        $user = get_user_by('email', $email);

        if($user) {
            // Update display name if user already exists
            wp_update_user([
                'ID' => $user->ID,
                'display_name' => $name,
                'first_name' => $firstname,
                'last_name' => $lastname,
            ]);
        } else {
            $user_id = wp_create_user($email, wp_generate_password(), $email);
            wp_update_user([
                'ID' => $user_id,
                'display_name' => $name,
                'first_name' => $firstname,
                'last_name' => $lastname,
                'role' => 'customer'
            ]);
        }

        // 2. Insert completed scheduled event
        global $wpdb;
        $event_table       = $wpdb->prefix . 'cb_scheduled_events';
        $invitee_table     = $wpdb->prefix . 'cb_scheduled_event_invitees';
        $event_types_table = $wpdb->prefix . 'cb_event_types';

        $duration = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT duration FROM $event_types_table WHERE uuid = %s",
                $initial_session_uuid
            )
        );
        $duration = $duration ? intval($duration) : 0;

        $end_time = $start_time;
        if ($duration > 0) {
            $end_time = date('Y-m-d\TH:i:s\Z', strtotime($start_time . " +{$duration} minutes"));
        }

        // Upsert into scheduled events
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $event_table (uuid, event_type_id, location_id, name, start_time, end_time, status, created_ts, notes)
            VALUES (%s, %d, %s, %s, %s, %s, %s, NOW(), %s)
            ON DUPLICATE KEY UPDATE
            event_type_id = VALUES(event_type_id),
            location_id   = VALUES(location_id),
            name          = VALUES(name),
            start_time    = VALUES(start_time),
            end_time      = VALUES(end_time),
            status        = VALUES(status),
            notes         = VALUES(notes),
            updated_ts    = NOW()",
            $initial_session_uuid,
            $initial_session_id,
            $location_id,
            $initial_session,
            $start_time,
            $end_time,
            'completed',
            $notes
        ));

        // Retrieve the record id using the same uuid
        $event_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id 
                FROM {$event_table} 
                WHERE uuid = %s 
                AND event_type_id = %d 
                AND location_id = %s 
                AND start_time = %s 
                AND status = %s",
                $initial_session_uuid,
                $initial_session_id,
                $location_id,
                $start_time,
                'completed'
            )
        );

        // Upsert into invitees
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $invitee_table (scheduled_event_uuid, uuid, name, email, created_ts, updated_ts)
            VALUES (%s, %s, %s, %s, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
            name  = VALUES(name),
            email = VALUES(email),
            updated_ts = NOW()",
            $initial_session_uuid,
            wp_generate_uuid4(),
            $name,
            $email
        ));

        // 3. Create Completed WooCommerce order
        $order = wc_create_order();

        // Associate order with the user
        $order->set_customer_id($user->ID);

        // Add product line item
        $initial_product = wc_get_product($initial_product_id);
        if ($initial_product) {
            $order->add_product($initial_product);
        }

        // Use $user object directly for billing/shipping info
        $billing_address = [
            'first_name' => $user->first_name ?? '',
            'last_name'  => $user->last_name ?? '',
            'email'      => $user->user_email ?? '',
        ];
        $order->set_address($billing_address, 'billing');
        $order->set_address($billing_address, 'shipping');

        // Finalize order details
        $order->calculate_totals();
        $order->set_payment_method('walk-in');
        $order->set_payment_method_title('Walk-in Payment');
        $order->update_status('completed', 'Order created for walk-in booking', true);

        // Persist order_id back to event record
        $order_id = $order->get_id();
        if ($event_id) {
            $wpdb->update(
                $event_table,
                ['order_id' => $order_id],
                ['id' => $event_id] // precise targeting by primary key
            );
        }

        // 4. Send follow-up email with booking link
        $reset_link = wp_lostpassword_url();

        // Resolve product and URL
        $followup_product = wc_get_product($followup_product_id);
        $product_url = $followup_product ? get_permalink($followup_product->get_id()) : wc_get_page_permalink('shop');

        $followup_location_id = '';
        if(!$followup_session === 'spiritual companionship') {
            $followup_location_id = 2;
        }


        // Build dataset and encrypt
        $dataset = [
            'firstname' => $firstname,
            'lastname'  => $lastname,
            'email'     => $email,
            'session'   => $followup_session,
            'location'  => $followup_location_id,
            'date'      => $followup_date,
            'time'      => $followup_time,
        ];

        $encryption = new CB_Encryption();
        $encrypted  = $encryption->encrypt(wp_json_encode($dataset));

        // Append encrypted token to product URL
        $followup_url = add_query_arg(['token' => rawurlencode($encrypted)], $product_url);

        // Convert ISO date and time to 12H format in site timezone
        $tz = new \DateTimeZone(get_option('timezone_string') ?: 'America/Barbados');

        $dateObj = new \DateTime($followup_date, new \DateTimeZone('UTC'));
        $dateObj->setTimezone($tz);
        $formattedDate = $dateObj->format('F j, Y'); // e.g., April 3, 2026

        $timeObj = new \DateTime($followup_time, new \DateTimeZone('UTC'));
        $timeObj->setTimezone($tz);
        $formattedTime = $timeObj->format('g:i A'); // e.g., 10:30 AM

        // Compose email body
        $body = sprintf(
            "Dear %s,\n\n".
            "It was wonderful to meet you and I am delighted that you would like to continue.\n\n".
            "Recommended Follow-up: %s on %s at %s\n".
            "Password reset link: <a href=\"%s\">Reset Password</a>\n".
            "Follow-up booking: <a href=\"%s\">%s</a>\n\n".
            "Looking forward to the continued journey.\n\nRegards,\nMichael A. Clarke",
            $firstname,
            $followup_session,
            $formattedDate,
            $formattedTime,
            $reset_link,
            $followup_url,
            $product_url
        );

        // Send email (using CB_Mail wrapper which can be extended for logging, templates, etc.)
        CB_Mail::send_email($email, 'Follow-up Session Invitation', $body);

        // Return JSON success response
        wp_send_json_success([ 'success' => true, 'message' => 'Walk-in created' ]);

    }

    /**
     * Validate license key against Worker endpoint.
     */
    public static function validate_license(string $license = ''): array {
        $license = $license ?: get_option(CB_Constants::OPT_LICENSE_KEY, '');
        if (empty($license)) {
            return ['success' => false, 'message' => __('License key missing', 'calendly-bookings')];
        }

        $response = wp_remote_post(CB_Constants::CB_WORKER_ENDPOINT, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['license' => $license]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => __('Connection failed', 'calendly-bookings')];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($data['valid'])) {
            update_option(CB_Constants::OPT_LICENSE_KEY, $license, false);
            return ['success' => true, 'message' => __('License validated successfully.', 'calendly-bookings')];
        }

        return ['success' => false, 'message' => __('Invalid license key.', 'calendly-bookings')];
    }

    /**
     * Test API connection and license validity.
     */
    public static function validate_license_ajax(): void {
        self::require_admin();
        $license = sanitize_text_field(wp_unslash($_POST['license_key'] ?? ''));
        wp_send_json(self::validate_license($license));
    }

    public static function dismiss_update_notice(): void {
        self::require_admin();
        delete_user_meta(get_current_user_id(), 'cb_dismiss_update_notice');
        update_user_meta(get_current_user_id(), 'cb_update_notice_dismissed', time());
        wp_send_json_success(['message' => __('Update notice dismissed.', 'calendly-bookings')]);
    }

    public static function test_connection(): void {
        self::require_admin();
        $apiKey   = sanitize_text_field($_POST['api_key'] ?? get_option(CB_Constants::OPT_API_TOKEN));
        $uuid     = sanitize_text_field($_POST['user_uuid'] ?? get_option(CB_Constants::OPT_USER_UUID));
        $license  = sanitize_text_field($_POST['license_key'] ?? get_option(CB_Constants::OPT_LICENSE_KEY));

        $connection_ok = CB_API::instance()->manual_connection_test($apiKey, $uuid);
        $license_check = self::validate_license($license);

        $success = $connection_ok && $license_check['success'];
        $message = $connection_ok
            ? ($license_check['success'] ? 'Connection and license authenticated.' : 'Connection OK, license invalid.')
            : 'Connection failed.';

        wp_send_json(['success' => $success, 'message' => $message]);
    }

    /**
     * Save credentials after validation.
     */
    public static function save_credentials(): void {
        self::require_admin();
        $apiKey   = sanitize_text_field($_POST['api_key'] ?? '');
        $uuid     = sanitize_text_field($_POST['user_uuid'] ?? '');
        $license  = sanitize_text_field($_POST['license_key'] ?? '');

        $errors = [];

        // Validate API/UUID
        if ($apiKey && $uuid && !CB_API::instance()->manual_connection_test($apiKey, $uuid)) {
            $errors[] = 'Invalid API key or UUID';
        }

        // Validate license
        $license_check = $license ? self::validate_license($license) : ['success' => true];

        if ($license && !$license_check['success']) {
            $errors[] = $license_check['message'];
        }

        // Save valid entries
        if (empty($errors)) {
            if ($apiKey)  update_option(CB_Constants::OPT_API_TOKEN, $apiKey);
            if ($uuid)    update_option(CB_Constants::OPT_USER_UUID, $uuid);
            if ($license && $license_check['success']) {
                update_option(CB_Constants::OPT_LICENSE_KEY, $license);
            }
            wp_send_json(['success' => true, 'message' => 'Credentials saved successfully.']);
        } else {
            wp_send_json(['success' => false, 'message' => implode(', ', $errors)]);
        }
    }


    public static function save_email_templates(): void {
        self::require_admin();

        $raw = wp_unslash($_POST['templates'] ?? []);
        if (!is_array($raw)) {
            wp_send_json_error(['message' => __('Invalid template data.', 'calendly-bookings')], 400);
        }

        CB_Email::save_templates($raw);

        $to = sanitize_text_field((string) ($_POST['email_to'] ?? ''));
        $from = sanitize_email((string) ($_POST['email_from'] ?? ''));
        $reply = sanitize_email((string) ($_POST['email_reply_to'] ?? ''));
        $bcc = sanitize_text_field((string) ($_POST['email_bcc'] ?? ''));

        update_option(CB_Constants::OPT_EMAIL_TO, $to, false);
        update_option(CB_Constants::OPT_EMAIL_FROM, $from, false);
        update_option(CB_Constants::OPT_EMAIL_REPLY_TO, $reply, false);
        update_option(CB_Constants::OPT_EMAIL_BCC, $bcc, false);

        wp_send_json_success(['message' => __('Email templates saved.', 'calendly-bookings')]);
    }

    public static function test_email(): void {
        self::require_admin();

        $key = sanitize_key((string) ($_POST['template'] ?? CB_Email::TEMPLATE_CONFIRMED));
        $to = sanitize_email((string) ($_POST['to'] ?? get_option('admin_email')));
        if (!$to || !is_email($to)) {
            wp_send_json_error(['message' => __('Enter a valid test recipient.', 'calendly-bookings')], 400);
        }

        $preview = CB_Email::preview($key);
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $from = sanitize_email((string) get_option(CB_Constants::OPT_EMAIL_FROM, ''));
        if ($from) $headers[] = 'From: ' . get_bloginfo('name') . ' <' . $from . '>';

        $sent = wp_mail($to, '[Test] ' . $preview['subject'], $preview['body'], $headers);
        if (!$sent) {
            wp_send_json_error(['message' => __('WordPress could not hand the test email to the mail transport.', 'calendly-bookings')], 500);
        }

        wp_send_json_success(['message' => __('Test email sent.', 'calendly-bookings')]);
    }

    public static function preview_email(): void {
        self::require_admin();
        $key = sanitize_key((string) ($_POST['template'] ?? CB_Email::TEMPLATE_CONFIRMED));
        wp_send_json_success(CB_Email::preview($key));
    }

    public static function schedule_individual_sync(): void {
        self::require_admin();
        check_ajax_referer('cb_admin_nonce', 'nonce');
        $sync_type = sanitize_text_field($_POST['sync_type'] ?? '');
        $frequency = sanitize_text_field($_POST['frequency'] ?? 'cb_daily');
    
        $map = [
            'cb_sync_events'      => 'cb_sync_scheduled_events_cron',
            'cb_sync_invitees'    => 'cb_sync_invitees_cron',
            'cb_sync_event_types' => 'cb_sync_event_types_cron',
            'cb_sync_locations'   => 'cb_sync_locations_cron',
        ];
    
        if (!isset($map[$sync_type])) {
            wp_send_json_error(['message' => 'Invalid sync type']);
        }
    
        $hook = $map[$sync_type];
        wp_clear_scheduled_hook($hook);
        $result = wp_schedule_event(time(), $frequency, $hook);
    
        if ($result === false) {
            wp_send_json_error(['message' => "Failed to schedule $sync_type. Frequency '$frequency' not registered."]);
        }
    
        update_option($sync_type . '_frequency', $frequency);
        update_option($sync_type, 1);
        wp_send_json_success(['message' => ucfirst(str_replace('cb_sync_', '', $sync_type)) . " sync scheduled ($frequency)."]);
    }

    /**
     * Clear an individual sync cron job.
     */
    public static function clear_individual_sync(): void {
        self::require_admin();
        check_ajax_referer('cb_admin_nonce', 'nonce');

        $sync_type = sanitize_text_field($_POST['sync_type'] ?? '');

        $map = [
            'cb_sync_events'      => 'cb_sync_scheduled_events_cron',
            'cb_sync_invitees'    => 'cb_sync_invitees_cron',
            'cb_sync_event_types' => 'cb_sync_event_types_cron',
            'cb_sync_locations'   => 'cb_sync_locations_cron',
        ];

        if (!isset($map[$sync_type])) {
            wp_send_json_error(['message' => 'Invalid sync type']);
        }

        wp_clear_scheduled_hook($map[$sync_type]);
        update_option($sync_type, 0);

        wp_send_json_success(['message' => ucfirst(str_replace('cb_sync_', '', $sync_type)) . ' sync cleared.']);
    }

    public static function clear_individual_crons(): void {
        self::require_admin();
        check_ajax_referer('cb_admin_nonce', 'nonce');

        $hooks = [
            'cb_sync_scheduled_events_cron',
            'cb_sync_invitees_cron',
            'cb_sync_event_types_cron',
            'cb_sync_locations_cron',
        ];
        foreach (['cb_sync_events','cb_sync_invitees','cb_sync_event_types','cb_sync_locations'] as $option) update_option($option, 0);

        foreach ($hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        wp_send_json_success(['message' => 'All individual syncs cleared.']);
    }

    public static function get_active_crons(): void {
        self::require_admin();
        wp_send_json_success(CB_Sync_Status::schedules());
    }

    public static function clear_master_sync(): void {
        self::require_admin();
        wp_clear_scheduled_hook('cb_sync_master_cron');
        delete_option('cb_master_frequency');
        update_option('cb_master_sync', 0);
        wp_send_json_success(['message' => __('Master sync cleared.', 'calendly-bookings')]);
    }

    public static function schedule_master_sync(): void {
        self::require_admin();
        // Verify request
        check_ajax_referer('cb_admin_nonce', 'nonce');

        $frequency = sanitize_text_field($_POST['frequency'] ?? 'daily');

        // Clear existing job
        wp_clear_scheduled_hook('cb_sync_master_cron');

        // Try to schedule with whatever frequency was posted
        $result = wp_schedule_event(time(), $frequency, 'cb_sync_master_cron');

        if ($result === false) {
            wp_send_json_error(['message' => "Failed to schedule master sync. Frequency '$frequency' not registered."]);
        }

        update_option('cb_master_frequency', $frequency);
        update_option('cb_master_sync', 1);

        wp_send_json_success(['message' => "Master sync scheduled ($frequency)."]);
    }


    public static function sync_scheduled_events_now(): void {
        self::require_admin();
        $api = CB_API::instance();
        $types = $api->sync_event_types();
        $result = $api->sync_scheduled_events(null, get_option(CB_Constants::OPT_MIN_START_DATE) ?: null);
        $invitees = $api->sync_scheduled_event_invitees();
        $errors = array_merge((array)($types['errors'] ?? []), (array)($result['errors'] ?? []), (array)($invitees['errors'] ?? []));
        if (!empty($errors)) wp_send_json_error(['message'=>implode('; ', array_map('sanitize_text_field', $errors))], 500);
        wp_send_json_success(['message'=>__('Scheduled events refreshed from Calendly.', 'calendly-bookings')]);
    }

    public static function get_event_availability(): void {
        check_ajax_referer('wp_rest', '_ajax_nonce');

        $uuid = sanitize_text_field($_POST['uuid'] ?? '');
        $start_iso = sanitize_text_field($_POST['start_iso'] ?? '');

        try {
            if (!preg_match('/^[A-Za-z0-9-]+$/', $uuid)) {
                wp_send_json_error(['message' => __('Invalid Calendly Event Type UUID.', 'calendly-bookings')], 400);
            }
            // Preserve the original UUID mapping while supplying the full URI required
            // by Calendly's GET /event_type_available_times endpoint.
            $uri = 'https://api.calendly.com/event_types/' . rawurlencode($uuid);
            $results = CB_API::instance()->get_event_type_availability($uri, $start_iso);
            if (!empty($results['error'])) {
                wp_send_json_error(['message' => (string)($results['message'] ?? 'Availability request failed.')], absint($results['status'] ?? 500) ?: 500);
            }
            wp_send_json_success($results['collection'] ?? []);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Save report defaults. Report generation itself is handled by CB_Reports.
     */
    public static function save_report_settings(): void {
        self::require_admin();

        $fields = array_map('sanitize_key', (array) ($_POST['fields'] ?? []));
        $format = sanitize_key((string) ($_POST['filetype'] ?? 'csv'));
        if (!in_array($format, ['csv','xlsx','pdf'], true)) {
            $format = 'csv';
        }

        update_option('cb_report_fields', $fields, false);
        update_option('cb_report_filetype', $format, false);
        update_option('cb_report_start', self::valid_report_date($_POST['start_date'] ?? ''), false);
        update_option('cb_report_end', self::valid_report_date($_POST['end_date'] ?? ''), false);

        wp_send_json_success(['message' => __('Report settings saved.', 'calendly-bookings')]);
    }

    public static function process_report(): void {
        self::require_admin();
        $id = absint($_POST['report_id'] ?? 0);
        if (!$id) wp_send_json_error(['message' => 'Missing report ID.'], 400);
        CB_Reports::process_report($id);
        wp_send_json_success(CB_Reports::list(50));
    }

    public static function get_reports(): void {
        self::require_admin();
        wp_send_json_success(CB_Reports::list(50));
    }

    public static function preview_report(): void {
        self::require_admin();

        $start = self::valid_report_date($_POST['start_date'] ?? '');
        $end = self::valid_report_date($_POST['end_date'] ?? '');
        $type = sanitize_key((string) ($_POST['report_type'] ?? 'sales_general'));
        $fields = array_map('sanitize_key', (array) ($_POST['fields'] ?? []));

        try {
            wp_send_json_success(CB_Reports::preview($start, $end, $type, $fields));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }

    public static function generate_report(): void {
        self::require_admin();

        $start = self::valid_report_date($_POST['start_date'] ?? '');
        $end = self::valid_report_date($_POST['end_date'] ?? '');
        $type = sanitize_key((string) ($_POST['report_type'] ?? 'sales_general'));
        $format = sanitize_key((string) ($_POST['file_type'] ?? 'csv'));
        $fields = array_map('sanitize_key', (array) ($_POST['fields'] ?? []));

        $result = CB_Reports::create($start, $end, $format, $type, $fields, get_current_user_id());
        if (empty($result['success'])) {
            wp_send_json_error(['message' => $result['message'] ?? __('Unable to queue report.', 'calendly-bookings')], 400);
        }

        wp_send_json_success(array_merge($result, ['reports' => CB_Reports::list(50)]));
    }

    public static function generate_scheduled_report(): void {
        self::require_admin();

        $start = gmdate('Y-m-d', strtotime('-1 day'));
        $end = $start;
        $format = sanitize_key((string) get_option(CB_Constants::OPT_REPORT_FILETYPE, 'csv'));
        $fields = (array) get_option('cb_report_fields', []);

        $result = CB_Reports::create($start, $end, $format, 'sales_general', $fields, get_current_user_id());
        if (empty($result['success'])) {
            wp_send_json_error(['message' => $result['message'] ?? __('Unable to queue scheduled report.', 'calendly-bookings')]);
        }

        wp_send_json_success($result);
    }

    public static function delete_report(): void {
        self::require_admin();
        $id = absint($_POST['report_id'] ?? 0);

        if (!$id || !CB_Reports::delete($id)) {
            wp_send_json_error(['message' => __('Report not found.', 'calendly-bookings')], 404);
        }

        wp_send_json_success(['message' => __('Report deleted.', 'calendly-bookings'), 'reports' => CB_Reports::list(50)]);
    }

    public static function bulk_delete_reports(): void {
        self::require_admin();
        $ids = array_values(array_filter(array_map('absint', (array) ($_POST['report_ids'] ?? []))));

        foreach ($ids as $id) {
            CB_Reports::delete($id);
        }

        wp_send_json_success(['message' => __('Selected reports deleted.', 'calendly-bookings'), 'reports' => CB_Reports::list(50)]);
    }

    public static function download_report(): void {
        self::require_admin();
        $id = absint($_GET['report_id'] ?? 0);
        if (!$id) {
            wp_die(esc_html__('Report not found.', 'calendly-bookings'), 404);
        }
        CB_Reports::download($id);
    }

    public static function bulk_download_reports(): void {
        self::require_admin();
        $ids = (array) ($_POST['report_ids'] ?? []);
        CB_Reports::bulk_download($ids);
    }

    private static function valid_report_date($value): string {
        $value = sanitize_text_field(wp_unslash((string) $value));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : gmdate('Y-m-d');
    }

}
