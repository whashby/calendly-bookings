<?php
/* includes/modules/class-cb-admin-ajax.php
 * Handles AJAX requests for admin actions like updating scheduled events, adding notes, maintenance tasks, and creating walk-ins.
 */
namespace Calendly_Bookings\Modules;

use Calendly_Bookings\Utils\CB_Audit_Log;
use Calendly_Bookings\Utils\CB_Encryption;
use Calendly_Bookings\Utils\CB_Mail;
use Calendly_Bookings\CB_Scheduled_Events;

if (!defined('ABSPATH')) {exit;}

final class CB_Admin_Ajax {

    /**
     * Initialize AJAX handlers for admin actions.
     */
    public static function init(): void {

        // Inline edit: single row update
        add_action(
            'wp_ajax_calendly_bookings_update_scheduled_event',
            [__CLASS__, 'update_scheduled_event']
        );

        // Bulk update: multiple rows
        add_action(
            'wp_ajax_calendly_bookings_bulk_update_scheduled_events',
            [__CLASS__, 'bulk_update_scheduled_events']
        );
        
        add_action(
            'wp_ajax_calendly_bookings_add_admin_notes',
            [__CLASS__, 'add_admin_notes']
        );
        
        add_action(
            'wp_ajax_cb_maintenance_action',
            [__CLASS__, 'maintenance_action']
        );
        
        add_action(
            'wp_ajax_cb_create_walkin',
            [__CLASS__, 'create_walkin']
        );
    
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
    public static function create_walkin(): void {
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
        $start_time = $data['start_time'] ?? '';
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
            $followup_date,
            $followup_time,
            $reset_link,
            $followup_url,
            $product_url
        );

        // Send email
        //add_filter('wp_mail_from_name', fn() => 'Michael A. Clarke');
        //add_filter('wp_mail_from', fn() => 'michael@hierlife.com');
        
        CB_Mail::send_email($email, 'Follow-up Session Invitation', $body);
        
        // Remove filters afterwards to avoid affecting other plugins
        //remove_filter('wp_mail_from_name', '__return_false');
        //remove_filter('wp_mail_from', '__return_false');

        // Return JSON success response
        wp_send_json_success([ 'success' => true, 'message' => 'Walk-in created' ]);

    }

}
