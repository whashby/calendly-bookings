<?php
namespace Calendly_Bookings\Admin\Views;

if (!defined('ABSPATH')) exit;

global $wpdb;
$table = $wpdb->prefix . 'cb_audit_log';

// Filters
$level   = sanitize_text_field($_GET['level'] ?? '');
$action  = sanitize_text_field($_GET['action'] ?? '');
$context = sanitize_text_field($_GET['context'] ?? '');
$search  = sanitize_text_field($_GET['s'] ?? '');

$where   = [];
$params  = [];

if ($level)   { $where[] = 'level = %s'; $params[] = $level; }
if ($action)  { $where[] = 'action = %s'; $params[] = $action; }
if ($context) { $where[] = 'context = %s'; $params[] = $context; }
if ($search)  { $where[] = '(identifier LIKE %s OR details LIKE %s)'; $params[] = "%$search%"; $params[] = "%$search%"; }

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// Pagination
$per_page = 20;
$page     = max(1, intval($_GET['paged'] ?? 1));
$offset   = ($page - 1) * $per_page;

// Count
$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
$total = $params ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params)) : $wpdb->get_var($count_sql);

// Fetch
$sql = "SELECT id, timestamp, level, action, context, identifier, details
        FROM {$table} {$where_sql}
        ORDER BY timestamp DESC
        LIMIT %d OFFSET %d";
$query_params = array_merge($params, [$per_page, $offset]);
$logs = $wpdb->get_results($wpdb->prepare($sql, ...$query_params));
?>

<div class="wrap">
  <h1><?php esc_html_e('Audit Log', 'calendly-bookings'); ?></h1>
  <p><?php esc_html_e('Review API calls and actions recorded by the system.', 'calendly-bookings'); ?></p>

  <form method="get">
    <input type="hidden" name="page" value="calendly-bookings-audit-log" />
    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search identifier or details', 'calendly-bookings'); ?>" />
    <select name="level">
      <option value=""><?php esc_html_e('All Levels', 'calendly-bookings'); ?></option>
      <?php foreach (['info','warning','error'] as $lvl): ?>
        <option value="<?php echo esc_attr($lvl); ?>" <?php selected($level, $lvl); ?>><?php echo ucfirst($lvl); ?></option>
      <?php endforeach; ?>
    </select>
    <select name="action">
      <option value=""><?php esc_html_e('All Actions', 'calendly-bookings'); ?></option>
      <?php foreach ($wpdb->get_col("SELECT DISTINCT action FROM {$table} ORDER BY action ASC") as $act): ?>
        <option value="<?php echo esc_attr($act); ?>" <?php selected($action, $act); ?>><?php echo esc_html($act); ?></option>
      <?php endforeach; ?>
    </select>
    <select name="context">
      <option value=""><?php esc_html_e('All Contexts', 'calendly-bookings'); ?></option>
      <?php foreach ($wpdb->get_col("SELECT DISTINCT context FROM {$table} ORDER BY context ASC") as $ctx): ?>
        <option value="<?php echo esc_attr($ctx); ?>" <?php selected($context, $ctx); ?>><?php echo esc_html(ucfirst(str_replace('_',' ',$ctx))); ?></option>
      <?php endforeach; ?>
    </select>
    <button class="button"><?php esc_html_e('Filter', 'calendly-bookings'); ?></button>
  </form>

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
      <?php if ($logs): ?>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td><?php echo esc_html(date_i18n('M j, Y g:i A', strtotime($log->timestamp))); ?></td>
            <td><?php echo esc_html($log->level); ?></td>
            <td><?php echo esc_html($log->action); ?></td>
            <td><?php echo esc_html($log->context); ?></td>
            <td><?php echo esc_html($log->identifier); ?></td>
            <td>
              <button type="button"
                      class="cb-details-toggle button button-small"
                      data-details="<?php echo esc_attr($log->details); ?>">
                <?php esc_html_e('View', 'calendly-bookings'); ?>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="6"><?php esc_html_e('No audit log entries found.', 'calendly-bookings'); ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php
  // Pagination
  $total_pages = ceil($total / $per_page);
  if ($total_pages > 1): ?>
    <div class="tablenav">
      <div class="tablenav-pages">
        <?php
        echo paginate_links([
          'base'      => add_query_arg('paged', '%#%'),
          'format'    => '',
          'current'   => $page,
          'total'     => $total_pages,
          'add_args'  => [
            's'       => $search,
            'level'   => $level,
            'action'  => $action,
            'context' => $context,
          ],
          'prev_text' => __('« Prev', 'calendly-bookings'),
          'next_text' => __('Next »', 'calendly-bookings'),
        ]);
        ?>
      </div>
    </div>
  <?php endif; ?>
    <div id="cb-details-modal" style="display:none;">
      <div class="cb-modal-content">
        <pre id="cb-details-text"></pre>
      </div>
    </div>

</div>
