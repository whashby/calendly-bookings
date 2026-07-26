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
        add_action('wp_ajax_cb_validate_license', [__CLASS__, 'validate_license']);

        add_action('wp_ajax_cb_schedule_individual_sync', [__CLASS__, 'schedule_individual_sync']);
        add_action('wp_ajax_cb_clear_individual_sync', [__CLASS__, 'clear_individual_sync']);
        add_action('wp_ajax_cb_clear_individual_crons', [__CLASS__, 'clear_individual_crons']);
        add_action('wp_ajax_cb_schedule_master_sync', [__CLASS__, 'schedule_master_sync']);
        add_action('wp_ajax_cb_clear_master_sync', [__CLASS__, 'clear_master_sync']);
        
        
        add_action('wp_ajax_cb_test_email', [__CLASS__, 'test_email']);
        add_action('wp_ajax_cb_preview_email', [__CLASS__, 'preview_email']);

        add_action('wp_ajax_cb_save_report_settings', [__CLASS__, 'save_report_settings']);
        add_action('wp_ajax_cb_get_reports', [__CLASS__, 'get_reports']);
        add_action('wp_ajax_cb_generate_report', [__CLASS__, 'generate_report']);
        add_action('wp_ajax_cb_generate_scheduled_report', [__CLASS__, 'generate_scheduled_report']);
        add_action('wp_ajax_cb_preview_report', [__CLASS__, 'preview_report']);
        add_action('wp_ajax_cb_delete_report', [__CLASS__, 'delete_report']);
        add_action('wp_ajax_cb_bulk_delete_reports', [__CLASS__, 'bulk_delete_reports']);
        add_action('wp_ajax_cb_download_report', [__CLASS__, 'download_report']);
        add_action('wp_ajax_cb_bulk_download_reports', [__CLASS__, 'bulk_download_reports']);

        add_action('wp_ajax_cb_get_active_crons', [__CLASS__, 'get_active_crons']);
        add_action('wp_ajax_cb_get_event_availability', [__CLASS__, 'get_event_availability']);
        add_action('wp_ajax_nopriv_cb_get_event_availability', [__CLASS__, 'get_event_availability']);

        // Schedule cron hook
        add_action('cb_generate_scheduled_report', [__CLASS__, 'generate_scheduled_report']);
    }

    /**
     * Handle single scheduled event update (notes, completed, etc.)
     */
    public static function update_scheduled_event(): void {
        $uuid  = sanitize_text_field($_POST['uuid'] ?? '');
        $status = isset($_POST['status']) ? $_POST['status'] : '';
        $notes = isset($_POST['notes']) ? (array) $_POST['notes'] : [];
        $data  = isset($_POST['data']) ? (array) $_POST['data'] : [];
    
        if (!$uuid) {
            wp_send_json_error(['message' => 'Missing uuid']);
        }
    
        if (!empty($status)) {
            $data['status'] = $status;
        }

        // Flatten notes array into a single string or structured JSON
        if (!empty($notes)) {
            $data['notes'] = wp_json_encode(array_map('sanitize_text_field', $notes));
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
        $uuids  = isset($_POST['uuids']) ? explode(',', sanitize_text_field($_POST['uuids'])) : [];
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
    
        // Define allowed statuses
        $allowed_statuses = ['scheduled', 'rescheduled', 'canceled', 'completed', 'pending'];
    
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
    
            $updated = $service->update_event($uuid, ['status' => $status]);
    
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
        $uuid  = sanitize_text_field($_POST['uuid'] ?? '');
        $notes= (array) $_POST['notes'] ?? [];
        
    
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
        

        $data['admin'] = $notes['admin'];


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
        // Decode JSON payload into array of name/value pairs
        $decoded = json_decode(stripslashes($_POST['data']), true);

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
    public static function test_connection(): void {
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


    public static function test_email(): void {
        $to = get_option(CB_Constants::OPT_EMAIL_TO);
        $subject = 'Calendly Bookings Test Email';
        $headers = [
            'From: ' . get_option(CB_Constants::OPT_EMAIL_FROM),
            'Reply-To: ' . get_option(CB_Constants::OPT_EMAIL_REPLY_TO),
            'Bcc: ' . get_option(CB_Constants::OPT_EMAIL_BCC),
        ];
        $message = CB_Email::build_email_content();
        wp_mail($to, $subject, $message, $headers);
        wp_send_json(['success' => true, 'message' => 'Test email sent.']);
    }

    public static function preview_email(): void {
        $content = CB_Email::build_email_content();
        wp_send_json(['success' => true, 'html' => $content]);
    }

    public static function schedule_individual_sync(): void {
        check_ajax_referer('cb_admin_nonce', 'nonce');
        $sync_type = sanitize_text_field($_POST['sync_type'] ?? '');
        $frequency = sanitize_text_field($_POST['frequency'] ?? 'cb_daily');
    
        $map = [
            'cb_sync_events'      => 'cb_run_events',
            'cb_sync_invitees'    => 'cb_run_invitees',
            'cb_sync_event_types' => 'cb_run_event_types',
            'cb_sync_locations'   => 'cb_run_locations',
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
        wp_send_json_success(['message' => ucfirst(str_replace('cb_sync_', '', $sync_type)) . " sync scheduled ($frequency)."]);
    }

    /**
     * Clear an individual sync cron job.
     */
    public static function clear_individual_sync(): void {
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

        wp_send_json_success(['message' => ucfirst(str_replace('cb_sync_', '', $sync_type)) . ' sync cleared.']);
    }

    public static function clear_individual_crons(): void {
        check_ajax_referer('cb_admin_nonce', 'nonce');

        $hooks = [
            'cb_sync_scheduled_events_cron',
            'cb_sync_invitees_cron',
            'cb_sync_event_types_cron',
            'cb_sync_locations_cron',
        ];

        foreach ($hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        wp_send_json_success(['message' => 'All individual syncs cleared.']);
    }

    public static function get_active_crons(): void {
        check_ajax_referer('cb_admin_nonce', 'nonce');

        $crons = _get_cron_array();
        $active = [];

        // Map sync types to cron hooks
        $map = [
            'master'           => 'cb_sync_master_cron',
            'scheduled_events' => 'cb_sync_scheduled_events_cron',
            'invitees'         => 'cb_sync_invitees_cron',
            'event_types'      => 'cb_sync_event_types_cron',
            'locations'        => 'cb_sync_locations_cron',
        ];

        foreach ($map as $type => $hook) {
            $active[$type] = [
                'enabled'   => false,
                'frequency' => null,
                'next_run'  => null,
            ];

            // Scan cron array for this hook
            foreach ($crons as $timestamp => $jobs) {
                if (isset($jobs[$hook])) {
                    $details = $jobs[$hook];
                    $active[$type] = [
                        'enabled'   => true,
                        'frequency' => $details['schedule'] ?? null,
                        'next_run'  => $timestamp,
                    ];
                    break; // Found the job, no need to keep scanning
                }
            }
        }

        wp_send_json_success($active);
    }
    
    public static function schedule_master_sync(): void {
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

        wp_send_json_success(['message' => "Master sync scheduled ($frequency)."]);
    }


    public static function get_event_availability(): void {
        check_ajax_referer('wp_rest', '_ajax_nonce');

        $uuid = sanitize_text_field($_POST['uuid'] ?? '');
        $start_iso = sanitize_text_field($_POST['start_iso'] ?? '');

        try {
            // Call your existing availability logic
            $results = CB_API::instance()->get_event_type_available_times($uuid, $start_iso);
            $slots = $results['collection'] ?? [];
            wp_send_json_success($slots);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Save report settings.
     */
    public static function save_report_settings() {
        check_ajax_referer('cb_admin_nonce','nonce');

        // --- General Sales ---
        update_option('cb_report_fields', array_map('sanitize_text_field', $_POST['fields'] ?? []));
        update_option('cb_report_filetype', sanitize_text_field($_POST['filetype'] ?? 'pdf'));
        update_option('cb_report_start', sanitize_text_field($_POST['start_date'] ?? ''));
        update_option('cb_report_end', sanitize_text_field($_POST['end_date'] ?? ''));

        // --- Product Sales ---
        update_option('cb_product_fields', array_map('sanitize_text_field', $_POST['product_fields'] ?? []));
        update_option('cb_product_filetype', sanitize_text_field($_POST['product_filetype'] ?? 'pdf'));
        update_option('cb_product_start', sanitize_text_field($_POST['product_start_date'] ?? ''));
        update_option('cb_product_end', sanitize_text_field($_POST['product_end_date'] ?? ''));

        // --- Discounts / Refunds ---
        update_option('cb_discount_fields', array_map('sanitize_text_field', $_POST['discount_fields'] ?? []));
        update_option('cb_discount_filetype', sanitize_text_field($_POST['discount_filetype'] ?? 'pdf'));
        update_option('cb_discount_start', sanitize_text_field($_POST['discount_start_date'] ?? ''));
        update_option('cb_discount_end', sanitize_text_field($_POST['discount_end_date'] ?? ''));

        // --- Sales Statistics ---
        update_option('cb_stats_fields', array_map('sanitize_text_field', $_POST['stats_fields'] ?? []));
        update_option('cb_stats_filetype', sanitize_text_field($_POST['stats_filetype'] ?? 'pdf'));
        update_option('cb_stats_start', sanitize_text_field($_POST['stats_start_date'] ?? ''));
        update_option('cb_stats_end', sanitize_text_field($_POST['stats_end_date'] ?? ''));

        wp_send_json_success(['message'=>'Settings saved successfully.']);
    }

    /**
     * Get all generated reports.
     */
    public static function get_reports() {
        check_ajax_referer('cb_admin_nonce', 'nonce');
        $reports = get_option('cb_generated_reports', []);
        wp_send_json_success($reports);
    }

    /**
     * Preview a report (HTML + summary).
     */
    public static function preview_report() {
        check_ajax_referer('cb_admin_nonce', 'nonce');

        $reportType = sanitize_text_field($_POST['report_type'] ?? 'sales_general');
        $start      = sanitize_text_field($_POST['start_date'] ?? '');
        $end        = sanitize_text_field($_POST['end_date'] ?? '');
        $fields     = array_map('sanitize_text_field', $_POST['fields'] ?? []);

        if (empty($start) || empty($end)) {
            wp_send_json_error(['message' => 'Missing date range']);
        }

        $orders = self::query_orders($start, $end, $reportType);
        [$html, $summary] = self::build_preview($orders, $fields, $reportType);

        wp_send_json_success(['html' => $html, 'summary' => $summary]);
    }

    public static function download_report() {
        check_ajax_referer('cb_admin_nonce','nonce');

        $id = intval($_GET['report_id'] ?? 0);
        $reports = get_option('cb_generated_reports', []);
        $report = null;

        foreach ($reports as $r) {
            if ($r['id'] == $id) {
                $report = $r;
                break;
            }
        }

        if (!$report) {
            wp_die('Report not found');
        }

        // Query orders again for this report
        $orders = self::query_orders($report['start_date'], $report['end_date'], $report['type']);

        // Call the export function
        self::export_report($orders, $report);
    }

    private static function export_report($orders, $report) {
        $fields     = $report['fields'];
        $type       = $report['file_type'];
        $id         = $report['id'];
        $reportType = $report['type'];

        // Build rows once
        $rows = self::render_report_table($orders, $fields);
        [$htmlPreview, $summary] = self::build_preview($orders, $fields, $reportType);

        switch ($type) {
            case 'csv':
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="report-' . $id . '.csv"');
                $out = fopen('php://output', 'w');
                foreach ($rows as $row) {
                    fputcsv($out, $row);
                }
                fputcsv($out, ['Summary']);
                fputcsv($out, [strip_tags($summary)]);
                fclose($out);
                exit;

            case 'xlsx':
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="report-' . $id . '.xlsx"');
                if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
                    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                    $sheet = $spreadsheet->getActiveSheet();
                    $sheet->fromArray($rows, NULL, 'A1');
                    $sheet->setCellValue('A' . (count($rows)+2), 'Summary: ' . strip_tags($summary));
                    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                    $writer->save('php://output');
                } else {
                    echo "XLSX export requires PhpSpreadsheet library.";
                }
                exit;

            case 'pdf':
            default:
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="report-' . $id . '.pdf"');

                if (class_exists(\Dompdf\Dompdf::class)) {
                    $options = new \Dompdf\Options();
                    $options->set('isRemoteEnabled', true);
                    $options->set('chroot', WP_CONTENT_DIR);
                    $options->set('defaultFont', 'DejaVu Sans');

                    $dompdf = new \Dompdf\Dompdf($options);

                    // Build HTML table from rows
                    $html = '<h1>Report</h1><table border="1" cellspacing="0" cellpadding="4"><thead><tr>';
                    foreach ($rows[0] as $label) {
                        $html .= '<th>' . esc_html($label) . '</th>';
                    }
                    $html .= '</tr></thead><tbody>';
                    for ($i=1; $i<count($rows); $i++) {
                        $html .= '<tr>';
                        foreach ($rows[$i] as $cell) {
                            $html .= '<td>' . esc_html($cell) . '</td>';
                        }
                        $html .= '</tr>';
                    }
                    $html .= '</tbody></table>';
                    $html .= '<h2>Summary</h2>' . $summary;

                    $dompdf->loadHtml($html);
                    $dompdf->setPaper('A4', 'portrait');
                    $dompdf->render();
                    $dompdf->stream("report-$id.pdf");
                } else {
                    echo "PDF export requires Dompdf library.";
                }
                exit;
        }
    }

    private static function render_report_table($orders, $fields) {
    // Build a 2D array: first row = labels, subsequent rows = values
    $rows = [];
    $labels = array_map([self::class, 'get_field_label'], $fields);
    $rows[] = $labels;

    foreach ($orders as $order) {
        $row = [];
        foreach ($fields as $field) {
            $row[] = self::get_field_value($order, $field);
        }
        $rows[] = $row;
    }

    return $rows;
}

    /**
     * Query WooCommerce orders for a given date range and report type.
     */
    private static function query_orders($start, $end, $reportType) {
        $args = [
            'status' => ['completed','processing','refunded'],
            'date_created' => $start . '...' . $end,
            'limit' => -1,
            'return' => 'objects',
        ];

        $query = new \WC_Order_Query($args);
        $orders = $query->get_orders();

        // You can filter by report type if needed
        if ($reportType === 'discounts_refunds') {
            $orders = array_filter($orders, function($order) {
                return $order->get_total_discount() > 0 || $order->get_total_refunded() > 0;
            });
        }

        return $orders;
    }

    /**
     * Build preview HTML and summary for orders.
     */
    private static function build_preview($orders, $fields, $reportType) {
        $html = '<table class="widefat"><thead><tr>';
        foreach ($fields as $field) {
            $html .= '<th>' . esc_html(self::get_field_label($field)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($orders as $order) {
            $html .= '<tr>';
            foreach ($fields as $field) {
                $html .= '<td>' . esc_html(self::get_field_value($order, $field)) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        // Summaries (as drafted earlier)
        $summary = '';
        switch ($reportType) {
            case 'sales_general':
                $totalRevenue = array_sum(array_map(fn($o) => $o->get_total(), $orders));
                $summary = "Total Orders: " . count($orders) . "<br>Total Revenue: " . wc_price($totalRevenue);
                break;

            case 'sales_product':
                $productCounts = [];
                foreach ($orders as $order) {
                    foreach ($order->get_items() as $item) {
                        $name = $item->get_name();
                        $productCounts[$name] = ($productCounts[$name] ?? 0) + $item->get_quantity();
                    }
                }
                $summary = "Units Sold:<br>";
                foreach ($productCounts as $name => $qty) {
                    $summary .= esc_html("$name: $qty") . "<br>";
                }
                break;

            case 'discounts_refunds':
                $discountTotal = array_sum(array_map(fn($o) => $o->get_total_discount(), $orders));
                $refundTotal   = array_sum(array_map(fn($o) => $o->get_total_refunded(), $orders));
                $summary = "Total Discounts: " . wc_price($discountTotal) . "<br>Total Refunds: " . wc_price($refundTotal);
                break;

            case 'sales_statistics':
                $statuses = [];
                foreach ($orders as $order) {
                    $statuses[$order->get_status()] = ($statuses[$order->get_status()] ?? 0) + 1;
                }
                $summary = "Order Status Breakdown:<br>";
                foreach ($statuses as $status => $count) {
                    $summary .= esc_html(ucfirst($status) . ": $count") . "<br>";
                }
                break;

            default:
                $summary = "Total Orders: " . count($orders);
        }

        return [$html, $summary];
    }

    /**
     * Create and persist a report with retention policy applied.
     */
    private static function create_report($start, $end, $type, $fields, $reportType) {
        $reports = get_option('cb_generated_reports', []);
        $id = uniqid('report_', true);

        $report = [
            'id'          => $id,
            'date_range'  => $start . ' → ' . $end,
            'file_type'   => $type,
            'fields'      => $fields,
            'type'        => $reportType,
            'created'     => time(),
            'download_url'=> admin_url("admin-ajax.php?action=cb_download_report&report_id={$id}&nonce=" . wp_create_nonce('cb_admin_nonce'))
        ];

        $reports[] = $report;

        // Retention policy
        $maxCount = (int) get_option('cb_report_retention_count', 10);
        $maxDays  = (int) get_option('cb_report_retention_days', 30);

        if ($maxCount > 0 && count($reports) > $maxCount) {
            $reports = array_slice($reports, -$maxCount);
        }
        if ($maxDays > 0) {
            $cutoff = time() - ($maxDays * DAY_IN_SECONDS);
            $reports = array_filter($reports, fn($r) => $r['created'] >= $cutoff);
        }

        update_option('cb_generated_reports', $reports, false);
        return [$report, $reports];
    }

    /**
     * Generate a report and return updated list.
     */
    public static function generate_report() {
        check_ajax_referer('cb_admin_nonce', 'nonce');

        $reportType = sanitize_text_field($_POST['report_type'] ?? 'sales_general');
        $start      = sanitize_text_field($_POST['start_date'] ?? '');
        $end        = sanitize_text_field($_POST['end_date'] ?? '');
        $type       = sanitize_text_field($_POST['file_type'] ?? 'pdf');
        $fields     = array_map('sanitize_text_field', $_POST['fields'] ?? []);

        if (empty($start) || empty($end)) {
            wp_send_json_error(['message' => 'Missing date range']);
        }

        [$report, $reports] = self::create_report($start, $end, $type, $fields, $reportType);

        wp_send_json_success([
            'message' => 'Report generated successfully.',
            'report'  => $report,
            'reports' => array_values($reports)
        ]);
    }

    private static function get_field_value($order, $field) {
        switch ($field) {
            case 'date':
            case 'transaction_date':
                return $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i') : '';

            case 'product':
            case 'products':
                $items = [];
                foreach ($order->get_items() as $item) {
                    $items[] = $item->get_name() . ' x' . $item->get_quantity();
                }
                return implode(', ', $items);

            case 'customer':
            case 'customer_name':
                return trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

            case 'customer_email':
                return $order->get_billing_email();

            case 'transaction_id':
                return $order->get_transaction_id();

            case 'approval_code':
                // If you store approval codes in meta
                return $order->get_meta('_approval_code');

            case 'coupon_code':
                $coupons = $order->get_coupon_codes();
                return implode(', ', $coupons);

            case 'discount_amount':
                return wc_price($order->get_total_discount());

            case 'vat':
            case 'tax':
                return wc_price($order->get_total_tax());

            case 'amount':
            case 'order_total':
                return wc_price($order->get_total());

            case 'status':
                return ucfirst($order->get_status());

            default:
                // Fallback: try meta field
                return $order->get_meta($field) ?: '—';
        }
    }

    private static function get_field_label($field) {
        $map = [
            'date'             => 'Transaction Date',
            'transaction_date' => 'Transaction Date',
            'product'          => 'Product(s)',
            'products'         => 'Product(s)',
            'customer'         => 'Customer Name',
            'customer_name'    => 'Customer Name',
            'customer_email'   => 'Customer Email',
            'transaction_id'   => 'Transaction ID',
            'approval_code'    => 'Approval Code',
            'coupon_code'      => 'Coupon Code',
            'discount_amount'  => 'Discount Amount',
            'vat'              => 'VAT',
            'tax'              => 'Tax',
            'amount'           => 'Order Total',
            'order_total'      => 'Order Total',
            'status'           => 'Order Status',
        ];

        return $map[$field] ?? ucwords(str_replace('_',' ', $field));
    }

    /**
     * Delete a single report.
     */
    public static function delete_report() {
        check_ajax_referer('cb_admin_nonce','nonce');
        $id = intval($_POST['report_id'] ?? 0);
        $reports = get_option('cb_generated_reports', []);
        $reports = array_filter($reports, fn($r) => $r['id'] != $id);
        update_option('cb_generated_reports', $reports);
        wp_send_json_success(['message'=>'Report deleted','reports'=>$reports]);
    }

    /**
     * Bulk delete reports.
     */
    public static function bulk_delete_reports() {
        check_ajax_referer('cb_admin_nonce', 'nonce');
        $ids = array_map('sanitize_text_field', $_POST['report_ids'] ?? []);
        $reports = get_option('cb_generated_reports', []);
        $reports = array_filter($reports, fn($r) => !in_array($r['id'], $ids, true));
        update_option('cb_generated_reports', $reports, false);
        wp_send_json_success(['message' => 'Selected reports deleted.', 'reports' => array_values($reports)]);
    }

    /**
     * Bulk download reports (CSV).
     */
    public static function bulk_download_reports() {
        check_ajax_referer('cb_admin_nonce', 'nonce');
		$raw_ids = $_POST['report_ids'] ?? [];
		if (is_array($raw_ids)) {
			$ids = array_map('sanitize_text_field', $raw_ids);
		} else {
			$ids = [sanitize_text_field($raw_ids)];
		}

        $reports = get_option('cb_generated_reports', []);
        $selected = array_filter($reports, fn($r) => in_array($r['id'], $ids, true));

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="reports.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date Range','File Type','Report Type','Fields','Created']);
        foreach ($selected as $report) {
            fputcsv($out, [$report['date_range'], $report['file_type'], $report['type'], implode(', ', $report['fields']), date('Y-m-d H:i:s', $report['created'])]);
        }
        fclose($out);
        exit;
    }

    /**
     * Scheduled report generation + email.
     */
    public static function generate_scheduled_report() {
        [$report, $reports] = self::create_report(date('Y-m-d'), date('Y-m-d'), 'pdf', ['date','product','amount'], 'sales_general');
        update_option('cb_generated_reports', $reports, false);

        $to = get_option('admin_email');
        $subject = 'Scheduled Report';
        $body = 'Your scheduled report has been generated. Download: ' . $report['download_url'];
        wp_mail($to, $subject, $body);
    }
}