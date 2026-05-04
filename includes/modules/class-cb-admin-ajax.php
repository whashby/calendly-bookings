<?php

namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;
use Calendly_Bookings\Modules\CB_Audit_Log;
use Calendly_Bookings\Modules\CB_Scheduled_Events;
use Calendly_Bookings\Utils\CB_Encryption;
use Calendly_Bookings\Utils\CB_Mail;
use Calendly_Bookings\Utils\CB_Timezone_Converter;

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
        add_action('wp_ajax_cb_test_email', [__CLASS__, 'test_email']);
        add_action('wp_ajax_cb_preview_email', [__CLASS__, 'preview_email']);

        add_action('wp_ajax_cb_generate_report', [__CLASS__, 'generate_report']);
        add_action('wp_ajax_cb_preview_report', [__CLASS__, 'preview_report']);

        add_action('wp_ajax_cb_schedule_individual_sync', [__CLASS__, 'schedule_individual_sync']);
        add_action('wp_ajax_cb_clear_individual_sync', [__CLASS__, 'clear_individual_sync']);
        add_action('wp_ajax_cb_clear_individual_crons', [__CLASS__, 'clear_individual_crons']);
        add_action('wp_ajax_cb_schedule_master_sync', [__CLASS__, 'schedule_master_sync']);

        add_action('wp_ajax_cb_get_active_crons', [__CLASS__, 'get_active_crons']);
        add_action('wp_ajax_cb_get_event_availability', [__CLASS__, 'get_event_availability']);
        add_action('wp_ajax_nopriv_cb_get_event_availability', [__CLASS__, 'get_event_availability']);


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
    public function create_walk_in(): void {
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
        $start_time = CB_Timezone_Converter::to_utc($data['start_time'] ?? '') ?? '';
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
                'role' => 'customer'
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

    public static function test_connection(): void {

        $test_api = sanitize_text_field($_POST['api_key']) ?: get_option(CB_Constants::OPT_API_TOKEN);
        $test_uuid = sanitize_text_field($_POST['user_uuid']) ?: get_option(CB_Constants::OPT_USER_UUID);;
        $test_license = sanitize_text_field($_POST['license_key'])?: get_option(CB_Constants::OPT_LICENSE_KEY);

        // Connection test logic
        $connection_ok = CB_API::instance()->manual_connection_test($test_api, $test_uuid);

        // License validation
        $license_valid = CB_API::instance()->validate_license($test_license);

        wp_send_json([
            'success' => $connection_ok && $license_valid,
            'message' => $connection_ok
                ? ($license_valid ? 'Connection and license authenticated.' : 'Connection OK, license invalid.')
                : 'Connection failed.'
        ]);
    }

public static function save_credentials(): void {
    // Collect posted values
    $posted_api     = sanitize_text_field($_POST['api_key'] ?? '');
    $posted_uuid    = sanitize_text_field($_POST['user_uuid'] ?? '');
    $posted_license = sanitize_text_field($_POST['license_key'] ?? '');

    // Resolve test values (fallback to stored if not entered)
    $test_api     = $posted_api ?: get_option(CB_Constants::OPT_API_TOKEN);
    $test_uuid    = $posted_uuid ?: get_option(CB_Constants::OPT_USER_UUID);
    $test_license = $posted_license ?: get_option(CB_Constants::OPT_LICENSE_KEY);

    // Validate entered values only
    $api_valid     = !$posted_api   || CB_API::instance()->manual_connection_test($test_api, $test_uuid);
    $uuid_valid    = !$posted_uuid  || CB_API::instance()->manual_connection_test($test_api, $test_uuid);
    $license_valid = !$posted_license || CB_API::instance()->validate_license($test_license);

    // Collect errors
    $errors = [];
    if ($posted_api   && !$api_valid)     { $errors[] = 'Invalid API key'; }
    if ($posted_uuid  && !$uuid_valid)    { $errors[] = 'Invalid user UUID'; }
    if ($posted_license && !$license_valid) { $errors[] = 'Invalid license key'; }

    // Save valid entries
    if ($posted_api   && $api_valid)     { update_option(CB_Constants::OPT_API_TOKEN, $test_api); }
    if ($posted_uuid  && $uuid_valid)    { update_option(CB_Constants::OPT_USER_UUID, $test_uuid); }
    if ($posted_license && $license_valid) { update_option(CB_Constants::OPT_LICENSE_KEY, $test_license); }

    // Response
    wp_send_json([
        'success' => empty($errors),
        'message' => empty($errors)
            ? 'Credentials saved successfully.'
            : implode(', ', $errors) . '. Please check and try again.'
    ]);
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

    public static function generate_report(): void {
        $filetype = get_option('cb_report_filetype', 'pdf');
        $content = CB_Reports::generate_report();

        $filename = 'calendly-report-' . date('Y-m-d') . '.' . $filetype;

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename={$filename}");
        header('Content-Transfer-Encoding: binary');
        echo $content;
        exit;
    }

    public static function preview_report(): void {
        $content = CB_Reports::generate_report();
        $filetype = get_option('cb_report_filetype', 'pdf');

        if ($filetype === 'pdf') {
            // For preview, embed as base64 PDF
            $base64 = base64_encode($content);
            $html = "<embed src='data:application/pdf;base64,{$base64}' type='application/pdf' width='100%' height='600px' />";
        } elseif ($filetype === 'csv') {
            $html = "<pre>" . esc_html($content) . "</pre>";
        } else {
            $html = "<p>Excel report generated. Download to view.</p>";
        }

        wp_send_json(['success' => true, 'html' => $html]);
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
}