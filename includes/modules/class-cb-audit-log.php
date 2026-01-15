<?php
// class-cb-audit-log.php

namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) exit;

final class CB_Audit_Log {

    /**
     * Write an audit log entry.
     *
     * @param string $action     What happened (e.g. sync, link, delete).
     * @param string $context    Where it happened (e.g. scheduled_events, product).
     * @param string $identifier Optional object ID or UUID.
     * @param array  $details    Optional structured data.
     * @param string $level      Severity level (info, warning, error).
     */
    public static function log(string $action, string $context, string $identifier = '', array $details = [], string $level = 'info'): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cb_audit_log';

        $wpdb->insert($table, [
            'timestamp'  => current_time('mysql'),
            'level'      => sanitize_text_field($level),
            'action'     => sanitize_text_field($action),
            'context'    => sanitize_text_field($context),
            'identifier' => sanitize_text_field($identifier),
            'details'    => wp_json_encode($details),
        ]);

        if ($wpdb->last_error) {
            error_log('[CB_Audit_Log] DB error: ' . $wpdb->last_error);
        } else {
            error_log(sprintf(
                '[CB_Audit_Log] %s | %s | %s | %s | %s',
                strtoupper($level),
                $action,
                $context,
                $identifier,
                wp_json_encode($details)
            ));
        }
    }
}
