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
        parse_str($_POST['data'], $data);

        $name  = sanitize_text_field($data['name']);
        $email = sanitize_email($data['email']);

        // 1. Create new WP user
        $user_id = wp_create_user($email, wp_generate_password(), $email);
        wp_update_user(['ID' => $user_id, 'display_name' => $name]);

        // 2. Insert completed scheduled event
        global $wpdb;
        $event_table   = $wpdb->prefix . 'cb_scheduled_events';
        $invitee_table = $wpdb->prefix . 'cb_scheduled_event_invitees';

        $uuid = wp_generate_uuid4();
        $wpdb->insert($event_table, [
            'uuid'       => $uuid,
            'event_type_id' => sanitize_text_field($data['initial_session_id']),
            'status'     => 'completed',
            'location_id'   => sanitize_text_field($data['initial_location']),
            'name' => sanitize_text_field($data['initial_session']),
            'notes'      => sanitize_textarea_field($data['initial_notes']),
            'scheduled_at' => $data['initial_date'].' '.$data['initial_time'],
        ]);

        $wpdb->insert($invitee_table, [
            'scheduled_event_uuid' => $uuid,
            'name'       		   => $name,
            'invitee_email'        => $email,
        ]);

        // 3. Create WooCommerce order
        $order = wc_create_order();
        $order->add_product(wc_get_product_by_event_type($data['initial_session']));
        $order->set_customer_id($user_id);
        $order->update_status('completed');
        $order_id = $order->get_id();

        $wpdb->update($event_table, ['order_id' => $order_id], ['uuid' => $uuid]);

        // 4. Send follow-up email
        $reset_link = wp_lostpassword_url();
        $followup_url = site_url('/events/'.$data['followup_session']);

        $firstname = explode(' ', $name)[0];
        $body = "Dear {$firstname},\n\n".
                "It was wonderful to meet you and I'm delighted that you would like to continue.\n\n".
                "Recommended Follow-up: {$data['followup_session']} on {$data['followup_date']} at {$data['followup_time']}\n".
                "Password reset link: {$reset_link}\n".
                "Follow-up booking: {$followup_url}\n\n".
                "Looking forward to the continued journey.\n\nRegards,\nMichael";

        wp_mail($email, 'Follow-up Session Invitation', $body);

        wp_send_json_success(['message' => 'Walk-in created']);
    }
}
