<?php
namespace Calendly_Bookings\Modules;
use Calendly_Bookings\CB_Constants;
if(!defined('ABSPATH'))exit;
final class CB_Admin{
    public static function init():void{add_action('admin_menu',[__CLASS__,'register_menu']);add_action('admin_enqueue_scripts',[__CLASS__,'enqueue_assets']);add_action('admin_notices',[__CLASS__,'show_admin_notices']);}
    public static function register_menu():void{
        add_menu_page(__('Calendly Bookings','calendly-bookings'),__('Calendly Bookings','calendly-bookings'),'manage_options','calendly-bookings',[__CLASS__,'render_control_panel'],'dashicons-calendar-alt',2);
        add_submenu_page('calendly-bookings',__('Maintenance','calendly-bookings'),__('Maintenance','calendly-bookings'),'manage_options','calendly-bookings-maintenance',[__CLASS__,'render_maintenance']);
        add_submenu_page('calendly-bookings',__('Audit Log','calendly-bookings'),__('Audit Log','calendly-bookings'),'manage_options','calendly-bookings-audit-log',[__CLASS__,'render_audit_log']);
        add_submenu_page('calendly-bookings',__('Settings','calendly-bookings'),__('Settings','calendly-bookings'),'manage_options','calendly-bookings-settings',[__CLASS__,'render_settings']);
    }
    public static function enqueue_assets(string $hook):void{
        if(!current_user_can('manage_options'))return;
        if($hook==='toplevel_page_calendly-bookings' || $hook==='calendly-bookings_page_calendly-bookings-maintenance'){
			$GLOBALS['CB_JS_Enqueued']=true;

            // Enqueue our refactored JS + CSS
            wp_enqueue_script(
                'cb-admin',
                CB_Constants::url('includes/admin/assets/cb-admin.js'),
                ['jquery'],
                CB_Constants::VERSION,
                true
            );

			wp_localize_script('cb-admin', 'CB_REST', trailingslashit(rest_url('calendly-bookings/v1/')));
            wp_localize_script('cb-admin', 'CB_REST_NONCE', wp_create_nonce('wp_rest'));

            /*wp_enqueue_style(
                'cb-admin',
                CB_Constants::url('includes/admin/assets/cb-admin.css'),
                [],
                CB_Constants::VERSION
            );*/
		}
        if($hook==='calendly-bookings_page_calendly-bookings-settings'){
			$GLOBALS['CB_JS_Enqueued']=true;
			wp_enqueue_script('cb-settings',CB_Constants::url('includes/admin/assets/settings.js'),['jquery'],CB_Constants::VERSION,true);
			wp_localize_script('cb-settings','CB_Rest',['root'=>esc_url_raw(rest_url()),'nonce'=>wp_create_nonce('wp_rest')]);
		}
    }
    private static function js_enqueued():bool{
        return !empty($GLOBALS['CB_JS_Enqueued']);
    }

    public static function show_admin_notices(): void { 
        if ($notice=get_transient('cb_event_notice')) {
            delete_transient('cb_event_notice');
            $class=$notice['type']==='success'?'notice-success':'notice-error';
            printf('<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',esc_attr($class),esc_html($notice['message']));
        }
    }

public static function render_control_panel(): void {
#	$foo = new CB_API();
#	$foo->foo();
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.', 'calendly-bookings'));
    }

    ?>
    <div class="wrap cb-admin-wrap">
        <h1><?php esc_html_e('Calendly Bookings Control Panel', 'calendly-bookings'); ?></h1>


        <hr>

        <h2><?php esc_html_e('Event Types', 'calendly-bookings'); ?></h2>
        <div class="cb-admin-actions">
            <button id="cb-refresh-event-types" class="button button-primary">
                <?php esc_html_e('Refresh Event Types', 'calendly-bookings'); ?>
            </button>
            <button id="cb-sync-now" class="button">
                <?php esc_html_e('Sync Upcoming Events', 'calendly-bookings'); ?>
            </button>
			<button id="cb-wc-create-all" class="button button-primary">
				<?php esc_html_e('Create All Products','calendly-bookings'); ?>
			</button>
			<button id="cb-wc-delete-all" class="button">
				<?php esc_html_e('Delete All Products','calendly-bookings'); ?>
			</button>        
		</div>
        <table class="widefat fixed striped" id="cb-event-types-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Name', 'calendly-bookings'); ?></th>
                    <th><?php esc_html_e('Duration', 'calendly-bookings'); ?></th>
                    <th><?php esc_html_e('Linked Product', 'calendly-bookings'); ?></th>
                    <th><?php esc_html_e('Actions', 'calendly-bookings'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                global $wpdb;
                $rows = $wpdb->get_results("SELECT uuid, name, duration, product_id FROM {$wpdb->prefix}cb_event_types ORDER BY name ASC");
                if ($rows) {
                    foreach ($rows as $row) {
                        ?>
                        <tr data-uuid="<?php echo esc_attr($row->uuid); ?>">
                            <td><?php echo esc_html($row->name); ?></td>
                            <td><?php echo esc_html($row->duration); ?> <?php esc_html_e('min', 'calendly-bookings'); ?></td>
                            <td>
                                <?php
                                if ($row->product_id) {
                                    echo esc_html($row->product_id) . ' - ' . esc_html(get_the_title($row->product_id));
                                } else {
                    
                                    esc_html_e('Not linked', 'calendly-bookings');
                                }
                                ?>
                            </td>
                            <td>
                                <button class="button cb-sync-event-type" data-uuid="<?php echo esc_attr($row->uuid); ?>">
                                    <?php esc_html_e('Sync', 'calendly-bookings'); ?>
                                </button>
                            <?php if (empty($row->product_id)) { ?>
                                <input type="number" class="small-text cb-product-id" placeholder="<?php echo esc_attr__('Product ID','calendly-bookings'); ?>" value="<?php echo ($row->product_id?intval($row->product_id):'');?>" />
                                <button class="button cb-wc-link" data-uuid="<?php echo esc_attr($row->uuid); ?>">
                                    <?php esc_html_e('Link', 'calendly-bookings'); ?>
                                </button>
                            <?php }
                                if (empty($row->product_id)) { ?>
                                <button class="button cb-wc-create" data-uuid="<?php echo esc_attr($row->uuid); ?>">
                                    <?php esc_html_e('Create Product', 'calendly-bookings'); ?>
                                </button>
                            <?php } else { ?>
                                <button class="button cb-wc-delete" data-uuid="<?php echo esc_attr($row->uuid); ?>">
                                    <?php esc_html_e('Delete Product', 'calendly-bookings'); ?>
                                </button>
                            <?php } ?>
                            </td>
                        </tr>
                        <?php
                    }
                } else {
                    ?>
                    <tr>
                        <td colspan="4"><?php esc_html_e('No event types found. Click "Refresh Event Types" to sync.', 'calendly-bookings'); ?></td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>

        <hr>

        <h2><?php esc_html_e('Upcoming Events', 'calendly-bookings'); ?></h2>
        <div id="cb-upcoming-events">
            <p><?php esc_html_e('Click "Sync Upcoming Events" to refresh this list.', 'calendly-bookings'); ?></p>
            <!-- JS will populate this -->
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

        // Show admin notices from fallbacks
        settings_errors('calendly_bookings');

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:1em;">';
        wp_nonce_field('cb_clear_cache_action', 'cb_clear_cache_nonce');
        echo '<input type="hidden" name="action" value="cb_clear_cache">';
        echo '<button id="cb-clear-cache" type="submit" class="button">' . esc_html__('Clear API Cache', 'calendly-bookings') . '</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:1em;">';
        wp_nonce_field('cb_rebuild_links_action', 'cb_rebuild_links_nonce');
        echo '<input type="hidden" name="action" value="cb_rebuild_links">';
        echo '<button id="cb-rebuild-links" type="submit" class="button">' . esc_html__('Rebuild Product Links', 'calendly-bookings') . '</button>';
        echo '</form>';

        echo '</div>';
    }

    public static function render_audit_log(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'calendly-bookings'));
        }

        $filter_event = isset($_GET['event']) ? sanitize_text_field($_GET['event']) : '';
        $filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        echo '<div class="wrap"><h1>' . esc_html__('Audit Log', 'calendly-bookings') . '</h1>';
        echo '<form method="get"><input type="hidden" name="page" value="calendly-bookings-audit-log">';
        echo '<label>' . esc_html__('Event Type:', 'calendly-bookings') . ' <input type="text" name="event" value="' . esc_attr($filter_event) . '"></label> ';
        echo '<label>' . esc_html__('Status:', 'calendly-bookings') . ' <input type="text" name="status" value="' . esc_attr($filter_status) . '"></label> ';
        echo '<button class="button">' . esc_html__('Filter', 'calendly-bookings') . '</button></form>';

        // For demo: read from error_log or a custom DB table
        $logs = get_option('cb_audit_log', []); // Replace with real storage
        echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Event</th><th>Status</th><th>Details</th></tr></thead><tbody>';
        foreach ($logs as $log) {
            if ($filter_event && stripos($log['event'], $filter_event) === false) continue;
            if ($filter_status && stripos($log['status'], $filter_status) === false) continue;
            echo '<tr><td>' . esc_html($log['time']) . '</td><td>' . esc_html($log['event']) . '</td><td>' . esc_html($log['status']) . '</td><td><pre>' . esc_html(print_r($log['details'], true)) . '</pre></td></tr>';
        }
        echo '</tbody></table></div>';
    }

}
