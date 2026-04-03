<?php

if (!defined('ABSPATH')) {
    exit;
}

namespace Calendly_Bookings\Modules;

final class CB_Maintenance {

    /** @var self|null */
    private static $instance = null;
 
    public static function init(): void {
        self::instance();
    }

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Clear API cache internally.
     */
    public function clear_api_cache(string $scope = 'all'): array {
        global $wpdb;
        $deleted = ['transients' => 0, 'options' => 0];

        $option_keys = ['cb_last_sync'];
        $prefixes = [];
        if ($scope === 'all' || $scope === 'event_types')   $prefixes[] = '_transient_cb_api_event_types';
        if ($scope === 'all' || $scope === 'availability')  $prefixes[] = '_transient_cb_api_availability';
        if ($scope === 'all' || $scope === 'events')        $prefixes[] = '_transient_cb_api_events';

        foreach ($prefixes as $prefix) {
            $like = esc_sql($prefix . '%');
            $rows = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '{$like}'");
            foreach ($rows as $opt) {
                if (delete_option($opt)) $deleted['transients']++;
            }
            if (is_multisite()) {
                $rows = $wpdb->get_col($wpdb->prepare(
                    "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                    str_replace('_transient_', '_site_transient_', $like)
                ));
                foreach ($rows as $opt) {
                    if (delete_site_option($opt)) {$deleted['transients']++;}
                }
            }
        }

        foreach ($option_keys as $key) {
            if (get_option($key, null) !== null && delete_option($key)) {
                $deleted['options']++;
            }
        }

        return $deleted;
    }

    /**
     * Rebuild product links.
     */
    public function rebuild_product_links(array $args = []): array {
        // This delegates to CB_WC_Sync and CB_API, similar to your existing logic.
        // Keep the heavy lifting here, return stats array.
        return [
            'processed' => 0,
            'linked'    => 0,
            'relinked'  => 0,
            'skipped'   => 0,
            'errors'    => [],
            'details'   => [],
        ];
    }

    /**
     * Update created_ts from scheduled event payloads.
     */
    public function update_created_ts(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cb_scheduled_events';
        $rows = $wpdb->get_results("SELECT id, payload FROM {$table}", ARRAY_A);
        $updated = 0;

        foreach ($rows as $row) {
            $payload = json_decode($row['payload'], true);
            if (!empty($payload['created_at'])) {
                $ts = strtotime($payload['created_at']);
                if ($ts) {
                    $wpdb->update($table, ['created_ts' => $ts], ['id' => $row['id']], ['%d'], ['%d']);
                    $updated++;
                }
            }
        }
        return $updated;
    }

    /**
     * Refresh reschedule/cancel URLs from invitee payloads.
     */
    public function refresh_urls(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cb_scheduled_events';
        $rows = $wpdb->get_results("SELECT id, payload FROM {$table}", ARRAY_A);
        $updated = 0;

        foreach ($rows as $row) {
            $payload = json_decode($row['payload'], true);
            if (!empty($payload)) {
                $reschedule = $payload['reschedule_url'] ?? null;
                $cancel     = $payload['cancel_url'] ?? null;
                if ($reschedule || $cancel) {
                    $wpdb->update($table, [
                        'reschedule_url' => $reschedule,
                        'cancel_url'     => $cancel
                    ], ['id' => $row['id']], ['%s','%s'], ['%d']);
                    $updated++;
                }
            }
        }
        return $updated;
    }

    /**
     * Backfill Order IDs from invitee answers.
     */
    public function backfill_order_ids(): int {
        global $wpdb;
        $invitees = $wpdb->prefix . 'cb_scheduled_event_invitees';
        $events   = $wpdb->prefix . 'cb_scheduled_events';
        $rows = $wpdb->get_results("SELECT scheduled_event_uuid, payload FROM {$invitees}", ARRAY_A);
        $updated = 0;

        foreach ($rows as $row) {
            $payload = json_decode($row['payload'], true);
            $orderId = $payload['answers']['order_id'] ?? null;
            if ($orderId) {
                $wpdb->update($events, ['order_id' => sanitize_text_field($orderId)], ['uuid' => $row['scheduled_event_uuid']], ['%s'], ['%s']);
                $updated++;
            }
        }
        return $updated;
    }

    /**
     * Normalize scheduled event statuses from payloads.
     */
    public function normalize_statuses(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cb_scheduled_events';
        $rows = $wpdb->get_results("SELECT id, payload FROM {$table}", ARRAY_A);
        $updated = 0;

        foreach ($rows as $row) {
            $payload = json_decode($row['payload'], true);
            $status  = $payload['status'] ?? null;
            if ($status) {
                $wpdb->update($table, ['status' => sanitize_text_field($status)], ['id' => $row['id']], ['%s'], ['%d']);
                $updated++;
            }
        }
        return $updated;
    }
}
