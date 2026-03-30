<?php

namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) exit;

final class CB_Admin_Ajax {

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
        $name  = $data['firstname'] . ' ' . $data['lastname'];
        $firstname = $data['firstname'];
        $email = sanitize_email($data['email']);
        $initial_session_id = $data['initial_session_id'];
        $initial_session_uuid = $data['initial_session_uuid'];
        $initial_session = $data['initial_session'] ?? '';
        $start_time = CB_Timezone_Converter::to_iso_time($data['start_time']);
        $notes = wp_json_encode($data['notes'] ?? []);
        $location = $data['location'];
        $followup_session = $data['followup_session'] ?? '';
        $followup_date = $data['followup_date'];
        $followup_time = $data['followup_time'];

        // 1. Create new WP user
        $user_id = wp_create_user($email, wp_generate_password(), $email);
        wp_update_user(['ID' => $user_id, 'display_name' => $name]);

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

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT * FROM $event_types_table WHERE uuid = %s AND location_id = %s AND start_time = %s AND status = 'completed'",
                $initial_session_uuid,
                $location,
                $start_time
            )
        );
        if ($existing) {
            $wpdb->update(
                $event_table,
            [
                'uuid'          => $initial_session_uuid,
                'event_type_id' => $initial_session_id,
                'location_id'   => $location,
                'name'          => $initial_session,
                'start_time'    => $start_time,
                'end_time'      => $end_time,
                'status'        => 'completed',
                'created_ts'    => current_time('mysql'),
                'notes'         => $notes,
            ],
            ['id' => $existing]
            );

            $wpdb->update($invitee_table, [
                'scheduled_event_uuid' => $initial_session_uuid,
                'name'                 => $name,
                'email'        => $email,
            ], [
                'scheduled_event_uuid' => $existing['uuid'],
                'email' => $email
            ]);
        }
        else {
            $wpdb->insert($event_table, [
                'uuid'          => $initial_session_uuid,
                'event_type_id' => $initial_session_id,
                'location_id'   => $location,
                'name'          => $initial_session,
                'start_time'    => $start_time,
                'end_time'      => $end_time,
                'status'        => 'completed',
                'created_ts'    => current_time('mysql'),
                'notes'         => $notes,
            ]);

            $wpdb->insert($invitee_table, [
                'scheduled_event_uuid' => $initial_session_uuid,
                'uuid'                 => wp_generate_uuid4(),
                'name'                 => $name,
                'email'                => $email,
            ]);

            // 3. Create WooCommerce order
            $order = wc_create_order();
            $order->add_product(wc_get_product_by_event_type($initial_session));
            $order->set_customer_id($user_id);
            $order->set_payment_method('walkin');
            $order->set_payment_method_title('Walk-in Payment');
            $order->payment_complete();

            $order_id = $order->get_id();
            $wpdb->update($event_table, ['order_id' => $order_id]);

            // 4. Send follow-up email
            $reset_link = wp_lostpassword_url();

            $product     = wc_get_product_by_event_type($followup_session);
            $product_url = $product ? get_permalink($product->get_id()) : site_url('/shop');

            // Build dataset and encrypt
            $dataset = json_encode([
                'session' => $followup_session,
                'date'    => $followup_date,
                'time'    => $followup_time,
            ]);
            $encrypted = CB_Encryption::encrypt($dataset);

            // Append encrypted token
            $followup_url = add_query_arg(['token' => urlencode($encrypted)], $product_url);

            $body = "Dear {$firstname},\n\n" .
                    "It was wonderful to meet you and I'm delighted that you would like to continue.\n\n" .
                    "Recommended Follow-up: {$followup_session} on {$followup_date} at {$followup_time}\n" .
                    "Password reset link: {$reset_link}\n" .
                    "Follow-up booking: {$followup_url}\n\n" .
                    "Looking forward to the continued journey.\n\nRegards,\nMichael A. Clarke";

            wp_mail($email, 'Follow-up Session Invitation', $body);
        }

        wp_send_json_success(['message' => 'Walk-in created']);
    }

}
