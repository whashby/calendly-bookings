<?php
namespace Calendly_Bookings\Modules;
use Calendly_Bookings\CB_Constants;

if (!defined('ABSPATH')) exit;

final class CB_Dashboard {
    public static function init(): void {
        add_action('wp_dashboard_setup', [__CLASS__, 'register_widgets']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets(string $hook): void {
        if ($hook !== 'index.php') return;
        wp_enqueue_script(
            'cb-dashboard',
            CB_Constants::url('includes/admin/assets/dashboard-widgets.js'),
            ['jquery'],
            CB_Constants::VERSION,
            true
        );
wp_localize_script(
    'cb-dashboard',
    'CB_REST',
    esc_url_raw( rest_url( 'calendly-bookings/v1/' ) )
);
wp_localize_script(
    'cb-dashboard',
    'CB_REST_NONCE',
    wp_create_nonce( 'wp_rest' )
);

        wp_enqueue_style(
            'cb-dashboard',
            CB_Constants::url('includes/admin/assets/dashboard-widgets.css'),
            [],
            CB_Constants::VERSION
        );
    }

    public static function register_widgets(): void {
        wp_add_dashboard_widget('cb_widget_today_bookings', __('Today’s Bookings','calendly-bookings'), [__CLASS__, 'render_today_bookings']);
        wp_add_dashboard_widget('cb_widget_upcoming_meetings', __('Upcoming Meetings','calendly-bookings'), [__CLASS__, 'render_upcoming_meetings']);
        wp_add_dashboard_widget('cb_widget_revenue_meetings', __('Revenue from Meetings','calendly-bookings'), [__CLASS__, 'render_revenue_meetings']);
        wp_add_dashboard_widget('cb_widget_booking_trends', __('Booking Trends','calendly-bookings'), [__CLASS__, 'render_booking_trends']);
        wp_add_dashboard_widget('cb_widget_availability', __('Next Available Slots','calendly-bookings'), [__CLASS__, 'render_availability']);
        wp_add_dashboard_widget('cb_widget_api_health', __('API Health & Sync','calendly-bookings'), [__CLASS__, 'render_api_health']);
        wp_add_dashboard_widget('cb_widget_data_integrity', __('Data Integrity','calendly-bookings'), [__CLASS__, 'render_data_integrity']);
    }

    public static function render_today_bookings(): void { echo '<div id="cb-today-bookings" class="cb-widget cb-widget-list"></div>'; }
    public static function render_upcoming_meetings(): void { echo '<div id="cb-upcoming-meetings" class="cb-widget cb-widget-list"></div>'; }
    public static function render_revenue_meetings(): void {
        echo '<div class="cb-widget cb-widget-kpis"><div class="kpi"><span class="label">'.esc_html__('This Month','calendly-bookings').'</span><span id="cb-revenue-month" class="value">—</span></div><div class="kpi"><span class="label">'.esc_html__('% vs Prev Month','calendly-bookings').'</span><span id="cb-revenue-mom" class="value">—</span></div></div>';
    }
    public static function render_booking_trends(): void { echo '<canvas id="cb-booking-trends" height="140"></canvas>'; }
    public static function render_availability(): void { echo '<div id="cb-availability" class="cb-widget cb-widget-list"></div>'; }
public static function render_api_health(): void {
    // Get stored last sync value
    $last_sync_raw = get_option('cb_last_sync');
    $last_sync_display = $last_sync_raw 
		? date_i18n('l, F j, Y \a\t g:i A', strtotime($last_sync_raw),false)
        : esc_html__('Never', 'calendly-bookings');
	
    echo '<div class="cb-widget cb-widget-kpis">';
        echo '<div class="kpi"><span class="label">' . esc_html__('Calendly API','calendly-bookings') . '</span><span id="cb-api-status" class="value">—</span></div>';
        echo '<div class="kpi"><span class="label">' . esc_html__('Last Sync','calendly-bookings') . '</span><button id="cb-sync-now" class="button button-small">' . esc_html__('Sync Now', 'calendly-bookings') . '</button><p><span id="cb-last-sync" class="value small-text">' . esc_html($last_sync_display) . '</span></p>';
        echo '</div>';
        echo '<div class="kpi"><span class="label">' . esc_html__('Errors (24h)','calendly-bookings') . '</span><span id="cb-errors-24h" class="value">—</span></div>';
    echo '</div>';
}
    public static function render_data_integrity(): void { echo '<div id="cb-data-integrity" class="cb-widget cb-widget-list"></div>'; }
}
