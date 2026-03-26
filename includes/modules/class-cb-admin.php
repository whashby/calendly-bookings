<?php
//includes/modules/class-cb-admin.php

namespace Calendly_Bookings\Modules;

use Calendly_Bookings\CB_Constants;
use Calendly_Bookings\Modules\CB_API;

if (!defined('ABSPATH')) {exit;}

final class CB_Admin {

    /**
     * Entry point called from includes/bootstrap.php
     */
    public static function init(): void {

        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_notices', [__CLASS__, 'show_admin_notices']);
    }

    /**
     * Register admin menu + submenus.
     */
    public static function register_menu(): void {

        $current_user = wp_get_current_user();
        $user_roles = $current_user->roles;

        // Check if user has CB-specific roles or is administrator
        $has_cb_admin_role = in_array('cb_administrator', $user_roles, true);
        $has_cb_support_role = in_array('cb_support', $user_roles, true);
        $is_admin = in_array('administrator', $user_roles, true);

        if(is_admin() && ($has_cb_admin_role || $has_cb_support_role || $is_admin)) {
            add_menu_page(
                __('Calendly Bookings', 'calendly-bookings'),
                __('Calendly Bookings', 'calendly-bookings'),
                'manage_options',
                'calendly-bookings',
                [__CLASS__, 'render_control_panel'],
                'dashicons-calendar-alt',
                2
            );

            if ($has_cb_admin_role) {
                add_submenu_page(
                    'calendly-bookings',
                    __('Scheduled Events', 'calendly-bookings'),
                    __('Scheduled Events', 'calendly-bookings'),
                    'manage_options',
                    'calendly-bookings-scheduled-events',
                    [__CLASS__, 'render_scheduled_events']
                );

            }
            // CB Support gets maintenance, audit log, products, and settings
            if ($has_cb_support_role) {
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
            }

            if($has_cb_admin_role || $has_cb_support_role) {
                add_submenu_page(
                    'calendly-bookings',
                    __('Settings', 'calendly-bookings'),
                    __('Settings', 'calendly-bookings'),
                    'manage_options',
                    'calendly-bookings-settings',
                    [__CLASS__, 'render_settings']
                );
            }

            // Regular administrators get basic access
            add_submenu_page(
                'calendly-bookings',
                __('Product Management', 'calendly-bookings'),
                __('Product Management', 'calendly-bookings'),
                'manage_options',
                'calendly-bookings-products',
                [__CLASS__, 'render_products']
            );
        }
    }

    /**
     * Enqueue admin JS/CSS.
     */
    public static function enqueue_assets(string $hook): void {
        if (!current_user_can('manage_options')) return;
    
        // All plugin admin screens
        $screens = [
            'toplevel_page_calendly-bookings',
            'calendly-bookings_page_calendly-bookings-scheduled-events',
            'calendly-bookings_page_calendly-bookings-maintenance',
            'calendly-bookings_page_calendly-bookings-products',
            'calendly-bookings_page_calendly-bookings-audit-log',
            'calendly-bookings_page_calendly-bookings-settings'
        ];
    
        if (in_array($hook, $screens, true)) {
            // Global admin assets
            wp_enqueue_script(
                'cb-admin',
                CB_Constants::url('includes/admin/assets/cb-admin.js', $screens),
                ['jquery'],
                CB_Constants::VERSION,
                true
            );
    
            wp_add_inline_script(
                'cb-admin',
                'const CB_REST = ' . wp_json_encode([
                    'root'  => esc_url_raw(rest_url('calendly-bookings/v1/')),
                    'nonce' => wp_create_nonce('wp_rest')
                ]) . ';',
                'before'
            );
    
            wp_enqueue_style(
                'cb-admin',
                CB_Constants::url('includes/admin/assets/cb-admin.css', $screens),
                [],
                CB_Constants::VERSION
            );
    
            // Page‑specific assets: derive slug from hook
            // Example: "calendly-bookings_page_calendly-bookings-settings"
            if (strpos($hook, 'calendly-bookings_page_') === 0) {
                $page = str_replace('calendly-bookings_page_calendly-bookings-', '', $hook);
    
                $script_path = "includes/admin/assets/cb-admin-{$page}.js";
                $style_path  = "includes/admin/assets/cb-admin-{$page}.css";
    
                if (file_exists(CB_Constants::path($script_path))) {
                    wp_enqueue_script(
                        "cb-admin-{$page}",
                        CB_Constants::url($script_path, $screens),
                        ['jquery', 'cb-admin'],
                        CB_Constants::VERSION,
                        true
                    );
                }

				wp_enqueue_script('thickbox'); 
				wp_enqueue_style('thickbox'); 
				
                if (file_exists(CB_Constants::path($style_path))) {
                    wp_enqueue_style(
                        "cb-admin-{$page}",
                        CB_Constants::url($style_path, $screens),
                        [],
                        CB_Constants::VERSION
                    );
                }

                // Flatpickr CSS
                wp_enqueue_style(
                    'flatpickr',
                    'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
                    [],
                    null
                );
            
                // Flatpickr JS
                wp_enqueue_script(
                    'flatpickr',
                    'https://cdn.jsdelivr.net/npm/flatpickr',
                    ['jquery'], // depends on jQuery
                    null,
                    true // load in footer
                );
            }
        }
    }
    /**
     * Display admin notices.
     */
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

    /**
     * View loader (Option A).
     */
    private static function view(string $path, array $vars = []): string {
        $file = CB_Constants::path("includes/admin/views/{$path}.php");
        if (!file_exists($file)) return '';
        extract($vars);
        ob_start();
        include $file;
        return ob_get_clean();
    }

    /**
     * Render: Control Panel
     */
    public static function render_control_panel(): void {
        echo self::view('control-panel/index');
    }

    /**
     * Render: Scheduled Events
     */
    public static function render_scheduled_events(): void {
    
        $name       = sanitize_text_field($_GET['name'] ?? '');
        $status     = sanitize_text_field($_GET['status'] ?? 'all');
        $start_date = sanitize_text_field($_GET['start_date'] ?? '');
        $end_date   = sanitize_text_field($_GET['end_date'] ?? '');
        $orderby    = sanitize_text_field($_GET['orderby'] ?? 'start_time');
        $order      = strtoupper(sanitize_text_field($_GET['order'] ?? 'DESC'));
        $page       = max(1, intval($_GET['paged'] ?? 1));
        $limit      = 50;
        $offset     = ($page - 1) * $limit;
    
		$filters = [];
		if (!empty($name)) {
			$filters['name'] = $name;
		}
		if ($status !== 'all') {
			$filters['status'] = $status;
		}
		if (!empty($start_date)) {
			$filters['start_date'] = $start_date;
		}
		if (!empty($end_date)) {
			$filters['end_date'] = $end_date;
		}

    
        $events = CB_Scheduled_Events::instance()->get_events($filters, [
            'orderby' => $orderby,
            'order'   => $order,
            'limit'   => $limit,
            'offset'  => $offset,
        ]);

        $total = CB_Scheduled_Events::instance()->count_events($filters);
    
        echo self::view('scheduled-events/index', [
            'filters' => $filters,
            'events'  => $events,
            'page'    => $page,
            'limit'   => $limit,
            'total'   => $total,
            'orderby' => $orderby,
            'order'   => $order,
        ]);
    }

    /**
     * Render: Maintenance
     */
    public static function render_maintenance(): void {
        echo self::view('maintenance/index');
    }

    /**
     * Render: Products
     */
    public static function render_products(): void {
        echo self::view('products/index');
    }

    /**
     * Render: Audit Log
     */
    public static function render_audit_log(): void {
        echo self::view('audit-log/index');
    }

    /**
     * Render: Settings
     */
    public static function render_settings(): void {
		
        echo self::view('settings/index');
    }

}
