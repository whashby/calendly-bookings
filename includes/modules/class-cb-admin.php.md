# Copy of class-cb-admin.php

```php
<?php
//includes/modules/class-cb-admin.php

namespace Calendly_Bookings\Modules;
use Calendly_Bookings\CB_Constants;
use Calendly_Bookings\Modules\CB_API;
use Calendly_Bookings\Utils\CB_Timezone_Converter;

if (!defined('ABSPATH')) exit;

final class CB_Admin {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_notices', [__CLASS__, 'show_admin_notices']);
		add_action('admin_head', function () {
			?>
			<style>
				.cb-details-toggle {
					padding: 5px;
					float: left;
				}
				/* Collapsed state: limit height, hide overflow */
				.cb-details {
					margin: 0;
					white-space: nowrap;
					overflow: hidden;
					text-overflow: ellipsis;
					max-width: 600px;
				}
				.cb-details.cb-expanded {
					white-space: pre-wrap;     /* allow wrapping */
					overflow: visible;
					text-overflow: unset;
				}
			</style>
			<?php
		});

		add_action('admin_footer', [__CLASS__, 'add_audit_toggle']);
	}

    public static function register_menu(): void {
        add_menu_page(
            __('Calendly Bookings', 'calendly-bookings'),
            __('Calendly Bookings', 'calendly-bookings'),
            'manage_options',
            'calendly-bookings',
            [__CLASS__, 'render_control_panel'],
            'dashicons-calendar-alt',
            2
        );
        add_submenu_page(
            'calendly-bookings',
            __('Scheduled Events', 'calendly-bookings'),
            __('Scheduled Events', 'calendly-bookings'),
            'manage_options',
            'calendly-bookings-scheduled-events',
            [__CLASS__, 'render_scheduled_events']
        );
        add_submenu_page(
            'calendly-bookings',
            __('Maintenance', 'calendly-bookings'),
            __('Maintenance', 'calendly-bookings'),
            'manage_options',
            'calendly-bookings-maintenance',
            [__CLASS__, 'render_maintenance']
        );
        add_submenu_page(
            'calendly-bookings',
            __('Audit Log', 'calendly-bookings'),
            __('Audit Log', 'calendly-bookings'),
            'manage_options',
            'calendly-bookings-audit-log',
            [__CLASS__, 'render_audit_log']
        );
        add_submenu_page(
            'calendly-bookings',
            __('Settings', 'calendly-bookings'),
            __('Settings', 'calendly-bookings'),
            'manage_options',
            'calendly-bookings-settings',
            [__CLASS__, 'render_settings']
        );
    }

    public static function enqueue_assets(string $hook): void {
        if (!current_user_can('manage_options')) return;

        if ($hook === 'toplevel_page_calendly-bookings' || $hook === 'calendly-bookings_page_calendly-bookings-maintenance' || $hook === 'calendly-bookings_page_calendly-bookings-scheduled-events' ) {
            $GLOBALS['CB_JS_Enqueued'] = true;

            wp_enqueue_script(
                'cb-admin',
                CB_Constants::url('includes/admin/assets/cb-admin.js'),
                ['jquery'],
                CB_Constants::VERSION,
                true
            );

			$data = [
				'root'  => trailingslashit( rest_url( 'calendly-bookings/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			];
			wp_add_inline_script(
				'cb-admin',
				'const CB_REST = ' . wp_json_encode( $data ) . ';',
				'before'
			);
        }

        if ($hook === 'calendly-bookings_page_calendly-bookings-settings') {
            $GLOBALS['CB_JS_Enqueued'] = true;
            wp_enqueue_script(
                'cb-settings',
                CB_Constants::url('includes/admin/assets/settings.js'),
                ['jquery'],
                CB_Constants::VERSION,
                true
            );
wp_localize_script(
    'cb-settings',
    'CB_Rest',
    [
        'root'  => esc_url_raw( rest_url( 'calendly-bookings/v1/' ) ),
        'nonce' => wp_create_nonce( 'wp_rest' ),
    ]
);

        }
    }

    public static function show_admin_notices(): void {
        if ($notice = get_transient('cb_event_notice')) {
            delete_transient('cb_event_notice');
            $class = $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
            printf(
                '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr($class),
                esc_html($notice['message'])
            );
        }
	}

public static function render_control_panel(): void {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.', 'calendly-bookings'));
    }

    // Fetch event types with linked products and scheduled events count
	$api = new CB_API();
	$event_types = $api->get_event_types();

    CB_Audit_Log::log('render_admin_panel', 'control_panel', '', [
        'event_types_count' => is_array($event_types) ? count($event_types) : 0
    ], 'info');
    ?>
    <div class="wrap cb-admin-wrap">
        <h1><?php esc_html_e('Calendly Bookings Control Panel', 'calendly-bookings'); ?></h1>

        <div id="cb-admin-notices"></div>
        <hr>

        <h2><?php esc_html_e('Event Types', 'calendly-bookings'); ?></h2>
        <div class="cb-admin-actions">
            <button id="cb-refresh-event-types" class="button button-primary">
                <?php esc_html_e('Refresh Event Types', 'calendly-bookings'); ?>
            </button>
            <button id="cb-wc-create-all" class="button button-primary">
                <?php esc_html_e('Create All Products','calendly-bookings'); ?>
            </button>
            <button id="cb-wc-delete-all" class="button">
                <?php esc_html_e('Delete All Products','calendly-bookings'); ?>
            </button>
        </div>

        <hr>

        <table class="widefat fixed striped" id="cb-event-types-table">
            <caption><?php esc_html_e('List of Calendly Event Types with linked WooCommerce products', 'calendly-bookings'); ?></caption>
            <thead>
                <tr>
                    <th><?php esc_html_e('Name', 'calendly-bookings'); ?></th>
                    <th><?php esc_html_e('Duration', 'calendly-bookings'); ?></th>
                    <th><?php esc_html_e('Linked Product', 'calendly-bookings'); ?></th>
                    <th><?php esc_html_e('Scheduled Events', 'calendly-bookings'); ?></th>
                    <th><?php esc_html_e('Actions', 'calendly-bookings'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($event_types)) : ?>
                    <?php foreach ($event_types as $event) : ?>
                        <tr data-uuid="<?php echo esc_attr($event['uuid']); ?>">
                            <td><?php echo esc_html($event['name']); ?></td>
                            <td><?php echo esc_html($event['duration']); ?> <?php esc_html_e('min', 'calendly-bookings'); ?></td>
                            <td class="cb-product-status">
                                <?php if ($event['product_id']) : ?>
                                    <a href="<?php echo esc_url(get_permalink($event['product_id'])); ?>">
                                        <?php echo esc_html($event['product_title']); ?>
                                    </a>
                                <?php else : ?>
                                    <?php esc_html_e('Not linked', 'calendly-bookings'); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo intval($event['scheduled_count']); ?></td>
                            <td>

                                <?php if (empty($event['product_id'])) : ?>
                                    <input type="number" class="small-text cb-product-id"
                                           placeholder="<?php echo esc_attr__('Product ID','calendly-bookings'); ?>" />
                                    <button class="button cb-wc-link" data-uuid="<?php echo esc_attr($event['uuid']); ?>">
                                        <?php esc_html_e('Link', 'calendly-bookings'); ?>
                                    </button>
                                    <button class="button cb-wc-create" data-uuid="<?php echo esc_attr($event['uuid']); ?>">
                                        <?php esc_html_e('Create Product', 'calendly-bookings'); ?>
                                    </button>
                                <?php else : ?>
                                    <button class="button cb-wc-delete" data-uuid="<?php echo esc_attr($event['uuid']); ?>">
                                        <?php esc_html_e('Delete Product', 'calendly-bookings'); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="5"><?php esc_html_e('No event types found. Click "Refresh Event Types" to sync.', 'calendly-bookings'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <hr>

        <h2><?php esc_html_e('Products Summary', 'calendly-bookings'); ?></h2>
        <div id="cb-product-summary">
            <?php self::render_product_summary(); ?>
        </div>
    </div>
    <?php
}

    public static function render_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'calendly-bookings'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Calendly Bookings Settings', 'calendly-bookings') . '</h1>';
        echo '<p class="description">' . esc_html__('Credentials are stored on the server and never exposed in the browser.', 'calendly-bookings') . '</p>';

        // No-JS fallback: use options API, blank values keep existing
        echo '<form id="cb-settings-form" method="post" action="options.php" style="margin-bottom:1em;">';
        settings_fields(CB_Constants::OPT_GROUP);
        do_settings_sections(CB_Constants::OPT_GROUP);

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row"><label for="cb_api_token">' . esc_html__('API Token', 'calendly-bookings') . '</label></th>';
        echo '<td><input type="password" id="cb_api_token" class="regular-text" placeholder="••••••••••••••••••••" autocomplete="off">';
        echo '<p class="description">' . esc_html__('Enter to overwrite existing token, or leave blank.', 'calendly-bookings') . '</p></td></tr>';

        echo '<tr><th scope="row"><label for="cb_user_uuid">' . esc_html__('User UUID', 'calendly-bookings') . '</label></th>';
        echo '<td><input type="text" id="cb_user_uuid" class="regular-text" placeholder="••••••••••••••••••••">';
        echo '<p class="description">' . esc_html__('Enter to overwrite existing UUID, or leave blank.', 'calendly-bookings') . '</p></td></tr>';

        echo '</tbody></table>';

        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Save Settings', 'calendly-bookings') . '</button></p>';
        echo '</form>';

        echo '<p><button id="cb-manual-test" class="button">' . esc_html__('Manual Connection Test', 'calendly-bookings') . '</button></p>';
        echo '<div id="cb-settings-status" style="margin-top:1em;font-weight:bold;"></div>';
        echo '</div>';
    }

    public static function render_maintenance(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'calendly-bookings'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Maintenance', 'calendly-bookings') . '</h1>';
        echo '<p>' . esc_html__('Run maintenance tasks, clear caches, rebuild product links, and manage webhooks.', 'calendly-bookings') . '</p>';

        // Notices container for JS
        echo '<div id="cb-admin-notices"></div>';

        echo '<div class="cb-admin-actions">';
        echo '<button id="cb-clear-cache" class="button">' . esc_html__('Clear API Cache', 'calendly-bookings') . '</button>';
        echo '<div id="cb-cache-summary" style="margin-top:0.5em;"></div>';

        echo '<button id="cb-rebuild-links" class="button">' . esc_html__('Rebuild Product Links', 'calendly-bookings') . '</button>';
        echo '<div id="cb-links-summary" style="margin-top:0.5em;"></div>';
        echo '<ul id="cb-links-details"></ul>';
        echo '</div>';

        echo '</div>';
    }

	public static function render_audit_log(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have permission to access this page.', 'calendly-bookings'));
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cb_audit_log';

		// Filters
		$level   = sanitize_text_field($_GET['level'] ?? '');
		$action  = sanitize_text_field($_GET['action'] ?? '');
		$context = sanitize_text_field($_GET['context'] ?? '');
		$search  = sanitize_text_field($_GET['s'] ?? '');

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
		$page     = max(1, intval($_GET['paged'] ?? 1));
		$offset   = ($page - 1) * $per_page;

		// Count total rows
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		$total = $params
			? (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params))
			: (int) $wpdb->get_var($count_sql);

		// Fetch rows (explicit columns for clarity)
		$sql = "SELECT id, timestamp, level, action, context, identifier, details
				FROM {$table} {$where_sql}
				ORDER BY timestamp DESC
				LIMIT %d OFFSET %d";

		$query_params = array_merge($params, [$per_page, $offset]);
		$logs = $wpdb->get_results($wpdb->prepare($sql, ...$query_params));

		// Dynamic filter options
		$actions  = $wpdb->get_col("SELECT DISTINCT action FROM {$table} ORDER BY action ASC");
		$contexts = $wpdb->get_col("SELECT DISTINCT context FROM {$table} ORDER BY context ASC");

		echo '<div class="wrap"><h1>' . esc_html__('Audit Log', 'calendly-bookings') . '</h1>';
		echo '<p>' . esc_html__('Review actions and API calls recorded by the system.', 'calendly-bookings') . '</p>';

		// Filter form
		echo '<form method="get"><input type="hidden" name="page" value="calendly-bookings-audit-log" />';
		echo '<input type="text" name="s" value="' . esc_attr($search) . '" placeholder="Search identifier or details" />';
		echo '<select name="level"><option value="">' . esc_html__('All Levels', 'calendly-bookings') . '</option>';
		foreach (['info','warning','error'] as $lvl) {
			echo '<option value="' . esc_attr($lvl) . '" ' . selected($level, $lvl, false) . '>' . ucfirst($lvl) . '</option>';
		}
		echo '</select>';
		echo '<select name="action"><option value="">' . esc_html__('All Actions', 'calendly-bookings') . '</option>';
		foreach ($actions as $act) {
			echo '<option value="' . esc_attr($act) . '" ' . selected($action, $act, false) . '>' . esc_html(ucfirst($act)) . '</option>';
		}
		echo '</select>';
		echo '<select name="context"><option value="">' . esc_html__('All Contexts', 'calendly-bookings') . '</option>';
		foreach ($contexts as $ctx) {
			echo '<option value="' . esc_attr($ctx) . '" ' . selected($context, $ctx, false) . '>' . esc_html(ucfirst(str_replace('_',' ',$ctx))) . '</option>';
		}
		echo '</select>';
		echo '<button class="button">' . esc_html__('Filter', 'calendly-bookings') . '</button>';
		echo '</form>';

		// Table
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:160px;white-space:nowrap;">' . esc_html__('Time', 'calendly-bookings') . '</th>';
		echo '<th>' . esc_html__('Level', 'calendly-bookings') . '</th>';
		echo '<th>' . esc_html__('Action', 'calendly-bookings') . '</th>';
		echo '<th>' . esc_html__('Context', 'calendly-bookings') . '</th>';
		echo '<th>' . esc_html__('Identifier', 'calendly-bookings') . '</th>';
		echo '<th>' . esc_html__('Details', 'calendly-bookings') . '</th>';
		echo '</tr></thead><tbody>';

		if ($logs) {
			foreach ($logs as $log) {
				$full  = $log->details ?? '';
				$short = mb_strimwidth($full, 0, 100, '…');

				echo '<tr>';
				echo '<td style="width:160px;white-space:nowrap;">' . esc_html(date_i18n('M j, Y g:i A', strtotime($log->timestamp))) . '</td>';
				echo '<td>' . esc_html($log->level) . '</td>';
				echo '<td>' . esc_html($log->action) . '</td>';
				echo '<td>' . esc_html($log->context) . '</td>';
				echo '<td>' . esc_html($log->identifier) . '</td>';
echo '<td>';
echo '<button type="button" class="cb-details-toggle button button-small" aria-expanded="false" style="margin-right:6px;">+</button>';
echo '<pre class="cb-details cb-collapsed">' . esc_html($full) . '</pre>';
echo '</td>';

				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="6">' . esc_html__('No audit log entries found.', 'calendly-bookings') . '</td></tr>';
		}

		echo '</tbody></table>';

		// Pagination links
		$total_pages = ceil($total / $per_page);
		if ($total_pages > 1) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
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
			]);
			echo '</div></div>';
		}

		echo '</div>';
	}

	public static function add_audit_toggle() {
		?>
		<script>
document.addEventListener('click', function(e) {
  if (!e.target.classList.contains('cb-details-toggle')) return;

  const btn = e.target;
  const row = btn.closest('tr');
  const details = row.querySelector('.cb-details');

  const expanded = details.classList.toggle('cb-expanded');
  if (expanded) {
    details.classList.remove('cb-collapsed');
    btn.textContent = '-';
    btn.setAttribute('aria-expanded', 'true');
  } else {
    details.classList.add('cb-collapsed');
    btn.textContent = '+';
    btn.setAttribute('aria-expanded', 'false');
  }
});

		</script>
		<?php
	}
public static function render_scheduled_events(): void {
    // Read filters from query string
    $status     = sanitize_text_field($_GET['status'] ?? 'active');
    $start_date = sanitize_text_field($_GET['start_date'] ?? '');
    $end_date   = sanitize_text_field($_GET['end_date'] ?? '');

    // Build filter array for get_scheduled_events
    $filters = ['status' => $status];
    if ($start_date) {
        $filters['start_date'] = $start_date;
    }
    if ($end_date) {
        $filters['end_date'] = $end_date;
    }

    // Audit: start
    CB_Audit_Log::log('render_start', 'scheduled_events', '', [
        'filters' => $filters
    ], 'info');

    // Fetch events via API wrapper
    $api = new CB_API();
    $events = $api->get_scheduled_events($filters,'',50);

    // Audit: query results
    CB_Audit_Log::log('render_query', 'scheduled_events', '', [
        'count'  => count($events),
        'status' => $status,
        'start'  => $start_date,
        'end'    => $end_date
    ], 'info');

    echo '<div class="wrap"><h1>' . esc_html__('Scheduled Events', 'calendly-bookings') . '</h1>';
    echo '<hr><div id="cb-admin-notices"></div>';
    echo '<form method="get">';
    echo '<input type="hidden" name="page" value="calendly-bookings-scheduled-events">';
    echo '<label>Status: <select name="status">';
    foreach (['active','canceled','completed'] as $s) {
        $sel = $status === $s ? 'selected' : '';
        echo "<option value='{$s}' {$sel}>" . ucfirst($s) . "</option>";
    }
    echo '</select></label> ';
    echo '<label>Start Date: <input type="date" name="start_date" value="' . esc_attr($start_date) . '"></label> ';
    echo '<label>End Date: <input type="date" name="end_date" value="' . esc_attr($end_date) . '"></label> ';
    echo '<button class="button">' . esc_html__('Filter', 'calendly-bookings') . '</button>';
    echo '</form><hr>';

    echo '<div id="cb-scheduled-events">';

    if (empty($events)) {
        echo '<p>' . esc_html__('No events found for the selected filters.', 'calendly-bookings') . '</p>';
        CB_Audit_Log::log('render_empty', 'scheduled_events', '', [
            'filters' => $filters
        ], 'warning');
    } else {
        echo '<table class="widefat fixed striped">';
        echo '<caption>' . esc_html__('Filtered Scheduled Events', 'calendly-bookings') . '</caption>';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Date/Time', 'calendly-bookings') . '</th>';
        echo '<th>' . esc_html__('Invitee', 'calendly-bookings') . '</th>';
        echo '<th>' . esc_html__('Event Name', 'calendly-bookings') . '</th>';
        echo '<th>' . esc_html__('Location', 'calendly-bookings') . '</th>';
        echo '<th>' . esc_html__('Order #', 'calendly-bookings') . '</th>';
        echo '<th>' . esc_html__('Actions', 'calendly-bookings') . '</th>';
        echo '<th>' . esc_html__('Status', 'calendly-bookings') . '</th>';
        echo '<th>' . esc_html__('Completed', 'calendly-bookings') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($events as $event) {
            $converter = new CB_Timezone_Converter();
            $datetime = $converter->to_site_time($event['start_time']);

            $invitee = !empty($event['invitee_name']) ? esc_html($event['invitee_name']) : '—';
            $location = !empty($event['location']) ? esc_html($event['location']) : '—';
            $order    = !empty($event['order_id']) ? esc_html($event['order_id']) : '—';
            $status    = !empty($event['status']) ? esc_html($event['status']) : '—';
            $event_name = !empty($event['event_name']) ? esc_html($event['event_name']) : esc_html($event['uuid']);

            echo '<tr>';
            echo '<td>' . esc_html($datetime) . '</td>';
            echo '<td>' . $invitee . '</td>';
            echo '<td>' . $event_name . '</td>';
            echo '<td>' . $location . '</td>';
            echo '<td>' . $order . '</td>';
            echo '<td>';
            if (!empty($event['reschedule_url'])) {
                echo '<a href="#" class="cb-modal-link" data-url="' . esc_url($event['reschedule_url']) . '">' . esc_html__('Reschedule', 'calendly-bookings') . '</a> ';
            }
            if (!empty($event['cancel_url'])) {
                echo '| <a href="#" class="cb-modal-link" data-url="' . esc_url($event['cancel_url']) . '">' . esc_html__('Cancel', 'calendly-bookings') . '</a>';
            }

            echo '</td>';
            echo '<td>' . $status . '</td>';
            echo '<td><input type="checkbox" name="completed[' . esc_attr($event['uuid']) . ']" ' . ($event['completed'] ? 'checked' : '') . '></td>';
            echo '</tr>';

            // Audit per-event
            CB_Audit_Log::log('render_event', 'scheduled_events', $event['uuid'], [
                'order_id' => $event['order_id'],
                'event_name' => $event['event_name'],
                'invitee' => $event['invitee_name'],
                'status' => $event['status'],
                'completed' => $event['completed']
            ], 'info');
        }

        echo '</tbody></table>';
    }

    echo '</div></div>';

    // Audit: end
    CB_Audit_Log::log('render_end', 'scheduled_events', '', [
        'filters' => $filters,
        'count'   => count($events)
    ], 'info');
}
											 
	private static function render_product_summary(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cb_event_types';

		$total     = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
		$linked    = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE product_id IS NOT NULL AND product_id > 0");
		$unlinked  = (int) $total - $linked;

		echo '<div class="cb-product-summary">';
		echo '<p>' . sprintf(esc_html__('Total Products: %d', 'calendly-bookings'), $total) . '</p>';
		echo '<p>' . sprintf(esc_html__('Linked to Event Types: %d', 'calendly-bookings'), $linked) . '</p>';
		echo '<p>' . sprintf(esc_html__('Unlinked: %d', 'calendly-bookings'), $unlinked) . '</p>';
		echo '</div>';
	}

	private static function extract_invitee_from_payload(string $json): string {
		$data = json_decode($json, true);

			error_log('Payload: ' . $json);


		if (!is_array($data)) return '—';

		$guests = $data['event_guests'] ?? [];
		if (!empty($guests[0]['email'])) {
			return $guests[0]['email'];
		}

		$memberships = $data['event_memberships'] ?? [];
		if (!empty($memberships[0]['user_name']) && !empty($memberships[0]['user_email'])) {
			return $memberships[0]['user_name'] . ' <' . $memberships[0]['user_email'] . '>';
		}

		return '—';
	}

}
```
