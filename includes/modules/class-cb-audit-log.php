<?php

if (!defined('ABSPATH')) {
    exit;
}

// class-cb-audit-log.php
namespace Calendly_Bookings\Modules;

class CB_Audit_Log {

  public static function rest_fetch(\WP_REST_Request $request) {
    global $wpdb;
    $table = $wpdb->prefix . 'cb_audit_log';

    // Filters
    $level   = sanitize_text_field($request->get_param('level'));
    $action  = sanitize_text_field($request->get_param('action'));
    $context = sanitize_text_field($request->get_param('context'));
    $search  = sanitize_text_field($request->get_param('s'));

    $where   = [];
    $params  = [];

    if ($level) {
      $where[]  = 'level = %s';
      $params[] = $level;
    }
    if ($action) {
      $where[]  = 'action = %s';
      $params[] = $action;
    }
    if ($context) {
      $where[]  = 'context = %s';
      $params[] = $context;
    }
    if ($search) {
      $where[]  = '(identifier LIKE %s OR details LIKE %s)';
      $params[] = '%' . $search . '%';
      $params[] = '%' . $search . '%';
    }

    $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    // Pagination
    $per_page = 20;
    $page     = max(1, intval($request->get_param('paged') ?? 1));
    $offset   = ($page - 1) * $per_page;

    // Count total rows
    $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
    $total = $params
      ? (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params))
      : (int) $wpdb->get_var($count_sql);

    // Fetch rows
    $sql = "SELECT id, timestamp, level, action, context, identifier, details
            FROM {$table} {$where_sql}
            ORDER BY timestamp DESC
            LIMIT %d OFFSET %d";

    $query_params = array_merge($params, [$per_page, $offset]);
    $logs = $wpdb->get_results($wpdb->prepare($sql, ...$query_params));

    // Render partials
    ob_start();
    echo \Calendly_Bookings\Modules\CB_Admin::view('audit/table', [
      'entries'  => $logs,
      'page'     => $page,
      'per_page' => $per_page,
      'total'    => $total,
      'filters'  => [
        's'       => $search,
        'level'   => $level,
        'action'  => $action,
        'context' => $context,
      ],
    ]);
    $html = ob_get_clean();

    return rest_ensure_response([
      'success' => true,
      'data'    => ['html' => $html]
    ]);
  }


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
