<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 4) . '/wp-load.php';
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
if ($admins) wp_set_current_user((int) $admins[0]);
$server = rest_get_server();
$routes = $server->get_routes();
$service = \Calendly_Bookings\Modules\CB_Scheduled_Events::instance();
$rows = $service->get_events([], ['limit' => 1]);
$output = ['routes_registered' => count(array_filter(array_keys($routes), fn($r) => str_starts_with($r, '/calendly-bookings/'))), 'event_query_ok' => $wpdb->last_error === '', 'events_present' => (bool) $rows];
if ($rows) {
  $req = new WP_REST_Request('GET', '/calendly-bookings/v1/scheduled-events/view/' . $rows[0]['uuid']);
  $res = $server->dispatch($req); $d = $res->get_data();
  $output['record_status'] = $res->get_status(); $output['record_success'] = !empty($d['success']);
  if (!empty($rows[0]['invitee_email'])) {
    $req = new WP_REST_Request('GET', '/calendly-bookings/v1/scheduled-events/invitee-history-by-email');
    $req->set_param('email', $rows[0]['invitee_email']);
    $res = $server->dispatch($req); $d = $res->get_data();
    $output['history_status'] = $res->get_status(); $output['history_success'] = !empty($d['success']);
    $output['history_array'] = is_array($d['data'] ?? null);
  }
}
$output['report_errors'] = $wpdb->get_col('SELECT error_message FROM ' . $wpdb->prefix . 'cb_reports WHERE status=\'failed\'');
$output['report_states'] = $wpdb->get_results('SELECT status, COUNT(*) AS jobs FROM ' . $wpdb->prefix . 'cb_reports GROUP BY status', ARRAY_A);
$output['report_query_ok'] = $wpdb->last_error === '';
$output['cron_disabled'] = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
$output['rest_uses_query_route'] = str_contains(rest_url('calendly-bookings/v1/'), '?');
echo wp_json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
