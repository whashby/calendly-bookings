<?php
global $wpdb;
$table = $wpdb->prefix . 'cb_audit_log';

list($where_sql, $params) = \Calendly_Bookings\Modules\CB_Audit_Log::build_where($filters);

$per_page = 20;
$page     = max(1, intval($_GET['paged'] ?? 1));
$offset   = ($page - 1) * $per_page;

$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
$total     = $params ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params))
                     : $wpdb->get_var($count_sql);

$sql = "SELECT id, timestamp, level, action, context, identifier, details
        FROM {$table} {$where_sql}
        ORDER BY " . esc_sql($orderby ?? 'timestamp') . " " . esc_sql($order ?? 'DESC') . "
        LIMIT %d OFFSET %d";

$logs = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$per_page, $offset])));
?>

<table class="widefat striped">
  <thead>
    <tr>
      <th><?php esc_html_e('Time', 'calendly-bookings'); ?></th>
      <th><?php esc_html_e('Level', 'calendly-bookings'); ?></th>
      <th><?php esc_html_e('Action', 'calendly-bookings'); ?></th>
      <th><?php esc_html_e('Context', 'calendly-bookings'); ?></th>
      <th><?php esc_html_e('Identifier', 'calendly-bookings'); ?></th>
      <th><?php esc_html_e('Details', 'calendly-bookings'); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$logs): ?>
      <tr><td colspan="6"><?php esc_html_e('No audit log entries found.', 'calendly-bookings'); ?></td></tr>
    <?php else: ?>
      <?php foreach ($logs as $log): ?>
        <tr>
          <td><?php echo esc_html(date_i18n('M j, Y g:i A', strtotime($log->timestamp))); ?></td>
          <td>
            <?php
              $level_class = 'cb-level-' . esc_attr($log->level);
              echo '<span class="' . $level_class . '">' . esc_html(ucfirst($log->level)) . '</span>';
            ?>
          </td>
          <td><?php echo esc_html($log->action); ?></td>
          <td><?php echo esc_html($log->context); ?></td>
          <td><?php echo esc_html($log->identifier); ?></td>
          <td>
            <button type="button"
                    class="cb-details-toggle button button-small"
                    aria-expanded="false"
                    aria-controls="cb-details-<?php echo esc_attr($log->id); ?>">
              +
            </button>
            <pre id="cb-details-<?php echo esc_attr($log->id); ?>"
                 class="cb-details cb-collapsed"><?php echo esc_html($log->details); ?></pre>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>

<?php
echo \Calendly_Bookings\Modules\CB_Admin::view('audit/pagination', [
  'page'     => $page,
  'per_page' => $per_page,
  'total'    => $total,
  'filters'  => $filters
]);
