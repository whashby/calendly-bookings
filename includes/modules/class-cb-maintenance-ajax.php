<?php
namespace Calendly_Bookings\Modules;

class CB_Maintenance_Ajax {

    public static function init(): void {
        // Register AJAX actions for logged-in admins
        add_action('wp_ajax_cb_maintenance_action', [__CLASS__, 'handle_action']);
    }

    /**
     * Handle AJAX requests from cb-admin-maintenance.js
     */
    public static function handle_action(): void {
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $subaction = sanitize_text_field($_POST['subaction'] ?? '');

        $maintenance = CB_Maintenance::instance();
        $result = null;
        $message = '';

        switch ($subaction) {
            case 'clear_cache':
                $deleted = $maintenance->clear_api_cache('all');
                $result  = $deleted;
                $message = sprintf('Cleared %d transients, %d options', $deleted['transients'], $deleted['options']);
                break;

            case 'rebuild_links':
                $stats   = $maintenance->rebuild_product_links(['force' => true]);
                $result  = $stats;
                $message = 'Rebuild product links completed';
                break;

            case 'update_created_ts':
                $count   = $maintenance->update_created_ts();
                $result  = ['updated' => $count];
                $message = "Updated {$count} created_ts values";
                break;

            case 'refresh_urls':
                $count   = $maintenance->refresh_urls();
                $result  = ['updated' => $count];
                $message = "Refreshed {$count} URLs";
                break;

            case 'backfill_order_ids':
                $count   = $maintenance->backfill_order_ids();
                $result  = ['updated' => $count];
                $message = "Backfilled {$count} order IDs";
                break;

            case 'normalize_statuses':
                $count   = $maintenance->normalize_statuses();
                $result  = ['updated' => $count];
                $message = "Normalized {$count} statuses";
                break;

            default:
                wp_send_json_error(['message' => 'Unknown maintenance action']);
        }

        wp_send_json_success([
            'message' => $message,
            'result'  => $result,
        ]);
    }
}
