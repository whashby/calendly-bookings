# Copy of class-cb-dashboard.php

```php
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

        wp_enqueue_script(
            'cb-dashboard-charts',
            CB_Constants::url('includes/admin/assets/dashboard-charts.js'),
            ['jquery'],
            CB_Constants::VERSION,
            true
        );

		$data = [
			'root'  => esc_url_raw( rest_url( 'cb/v1/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		];
		wp_add_inline_script(
			'cb-dashboard',
			'const CB_REST = ' . wp_json_encode( $data ) . ';',
			'before'
		);

        wp_enqueue_style(
            'cb-dashboard',
            CB_Constants::url('includes/admin/assets/dashboard-widgets.css'),
            [],
            CB_Constants::VERSION
        );
    }
	
	
	public static function render_dashboard(): void {
		echo '<div id="cb-widget-availability" class="cb-widget cb-widget-list"></div>';
		echo '<div id="cb-widget-integrity" class="cb-widget cb-widget-list"></div>';
		echo '<div id="cb-widget-health" class="cb-widget"></div>';
		echo '<div id="cb-widget-trends" class="cb-widget cb-widget-list"><h3>Booking Trends</h3><canvas id="cb-widget-trends-chart"></canvas></div>';
	}



	public static function register_widgets(): void {
		wp_add_dashboard_widget('cb_widget_availability', __('Next Available Slots','calendly-bookings'), [__CLASS__, 'render_availability']);
		wp_add_dashboard_widget('cb_widget_integrity', __('Data Integrity','calendly-bookings'), [__CLASS__, 'render_data_integrity']);
		wp_add_dashboard_widget('cb_widget_health', __('API Health & Sync','calendly-bookings'), [__CLASS__, 'render_api_health']);
		wp_add_dashboard_widget('cb_widget_booking_trends', __('Booking Trends','calendly-bookings'), [__CLASS__, 'render_booking_trends']);
		wp_add_dashboard_widget('cb_widget_performance', __('Performance','calendly-bookings'), [__CLASS__, 'render_performance']);
		wp_add_dashboard_widget('cb_widget_recent_bookings', __('Recent Bookings','calendly-bookings'), [__CLASS__, 'render_recent_bookings']);
		wp_add_dashboard_widget('cb_widget_revenues', __('Revenue from Meetings','calendly-bookings'), [__CLASS__, 'render_revenue']);
	}


	public static function render_availability(): void { echo '<div id="cb-widget-availability" class="cb-widget cb-widget-list"></div>'; }
    public static function render_data_integrity(): void { echo '<div id="cb-widget-integrity" class="cb-widget cb-widget-list"></div>'; }
	public static function render_revenue(): void { echo '<div id="cb-widget-revenue" class="cb-widget"></div>'; }
	public static function render_api_health(): void { echo '<div id="cb-widget-health" class="cb-widget"></div>';	}     
	public static function render_recent_bookings(): void { echo '<div id="cb-widget-recent" class="cb-widget"></div>'; }
	public static function render_booking_trends(): void { 
		echo '<div id="cb-widget-trends" class="cb-widget">
			<div class="cb-trends-controls">
			  <button id="cb-trends-1m" class="button">1M</button>
			  <button id="cb-trends-3m" class="button">3M</button>
			  <button id="cb-trends-6m" class="button">6M</button>
			  <button id="cb-trends-12m" class="button">12M</button>
			</div>
			<canvas id="cb-widget-trends-chart"></canvas>
		</div>';
	}
    public static function render_performance(): void { 
		echo '<div id="cb-widget-performance" class="cb-widget">
			<div class="cb-performance-controls">
			<button id="cb-perf-1m" class="button">1M</button>
			<button id="cb-perf-3m" class="button">3M</button>
			<button id="cb-perf-6m" class="button">6M</button>
			<button id="cb-perf-12m" class="button">12M</button>
			</div>
		</div>'; 
	}

}

```
