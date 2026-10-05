<?php
declare(strict_types=1);

namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) exit;

final class CB_Action_Scheduler {
    public static function init(): void {
        add_action('cb_booking_process_order_fallback', [CB_Booking_Reconciliation::class, 'process_order'], 10, 1);
        add_action('init', [__CLASS__, 'ensure_recurring_reconciliation']);
    }

    public static function ensure_recurring_reconciliation(): void {
        if (!function_exists('as_schedule_recurring_action')) return;
        if (!function_exists('as_has_scheduled_action')) return;
        if (!as_has_scheduled_action('cb_booking_reconcile_pending', [], 'calendly-bookings')) {
            as_schedule_recurring_action(time() + 300, 300, 'cb_booking_reconcile_pending', [], 'calendly-bookings');
        }
        add_action('cb_booking_reconcile_pending', [__CLASS__, 'reconcile_pending']);
    }

    public static function reconcile_pending(): void {
        // First recover orders that have explicit booking states.
        $orders = wc_get_orders([
            'limit' => 50,
            'return' => 'objects',
            'type' => 'shop_order',
            'status' => ['pending', 'processing', 'on-hold', 'completed'],
            'meta_query' => [
                [
                    'key' => '_cb_calendly_booking_status',
                    'value' => ['pending', 'failed'],
                    'compare' => 'IN',
                ],
            ],
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order) continue;
            $status = (string) $order->get_meta('_cb_calendly_booking_status', true);
            if (in_array($status, ['pending', 'failed'], true)) {
                CB_Booking_Reconciliation::enqueue((int) $order->get_id(), 0, 'reconciliation');
            }
        }

        // Recover recent meeting orders that reached WooCommerce but never got a
        // booking status. This closes the exact checkout -> worker gap that can
        // otherwise leave a free/paid meeting permanently stranded.
        $orphan_orders = wc_get_orders([
            'limit' => 50,
            'return' => 'objects',
            'type' => 'shop_order',
            'status' => ['processing', 'completed'],
            'date_created' => '>' . gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS),
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        foreach ($orphan_orders as $order) {
            if (!$order instanceof \WC_Order) continue;
            if ((string) $order->get_meta('_cb_calendly_booking_status', true) !== '') continue;
            if ((string) $order->get_meta('_cb_calendly_invitee_uri', true) !== '') continue;
            if (!CB_Checkout::order_has_meeting($order)) continue;

            $event_uuid = (string) $order->get_meta('_cb_event_uuid', true);
            $start_iso = (string) $order->get_meta('_cb_meeting_start_iso', true);
            if (!$event_uuid || !$start_iso) {
                // The line-item snapshot can still be promoted by the booking
                // worker, so queue it rather than abandoning the order.
                CB_Logger::debug('[CB Booking] Recovering orphan meeting order #' . $order->get_id() . ' from persisted line-item booking data.');
            } else {
                CB_Logger::debug('[CB Booking] Recovering orphan meeting order #' . $order->get_id() . '.');
            }
            CB_Booking_Reconciliation::enqueue((int) $order->get_id(), 0, 'orphan-recovery');
        }

        // Awaiting-webhook orders are intentionally not recreated. A missing
        // webhook is a reconciliation problem, not permission to create a second
        // Calendly invitee.
        $awaiting = wc_get_orders([
            'limit' => 50,
            'return' => 'objects',
            'type' => 'shop_order',
            'status' => ['processing', 'completed'],
            'meta_query' => [
                [
                    'key' => '_cb_calendly_booking_status',
                    'value' => 'awaiting_webhook',
                    'compare' => '=',
                ],
            ],
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        foreach ($awaiting as $order) {
            if (!$order instanceof \WC_Order) continue;
            $received = (string) $order->get_meta('_cb_calendly_webhook_received_at', true);
            $created = (string) $order->get_meta('_cb_calendly_last_attempt_at', true);
            if (!$received && $created && strtotime($created) < (time() - 300)) {
                $already_noted = (string) $order->get_meta('_cb_webhook_wait_note_at', true);
                if (!$already_noted || strtotime($already_noted) < (time() - DAY_IN_SECONDS)) {
                    $order->add_order_note('Calendly booking exists but webhook confirmation has not arrived within 5 minutes; manual reconciliation is required.');
                    $order->update_meta_data('_cb_webhook_wait_note_at', gmdate('c'));
                    $order->save();
                }
            }
        }
    }

}
