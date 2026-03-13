<?php
// includes/utils/functions.php
namespace Calendly_Bookings\Utils;
use Calendly_Bookings\Modules\CB_API;

if (!defined('ABSPATH')) exit;

function cb_resolve_timezone(): ?string {
    $tz = wp_timezone_string();
    return $tz ?: null;
}

/**
 * Redirect to checkout immediately for "Initial meeting" product.
 */
add_action('template_redirect', function () {
    // Only run on add-to-cart requests
    if (!isset($_REQUEST['add-to-cart'])) {
        return;
    }

    $product_id = absint($_REQUEST['add-to-cart']);
    $product = wc_get_product($product_id);
    if ($product) {
		$product_name = $product->get_name();

		if($product_name == "Initial meeting") {
			// Remove default added-to-cart redirect
			remove_action('template_redirect', 'wc_template_redirect');

			// Redirect straight to checkout
			wp_safe_redirect(wc_get_checkout_url());
			exit;
		}
    }
});


add_action('template_redirect', function() {
    global $wpdb;

    // Check if we're on /meeting-scheduled
    if (is_page('meeting-scheduled')) {
        // Grab query vars
        $start_raw     = isset($_GET['event_start_time']) ? sanitize_text_field($_GET['event_start_time']) : '';
        $order_number  = isset($_GET['answer_1']) ? intval($_GET['answer_1']) : 0;

        // If no payload and user not admin -> 404
        if (empty($start_raw) || empty($order_number)) {
            if (!current_user_can('manage_options')) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                nocache_headers();
                include get_query_template('404');
                exit;
            }
        }

        // Ensure scheduled events are refreshed
        $api = new CB_API();
        $api -> sync_scheduled_events();

        // Normalize start time
        $event_start = date('Y-m-d H:i:s', strtotime($start_raw));

        // Update scheduled events table
        if ($event_start && $order_number) {
            $wpdb->update(
                "{$wpdb->prefix}cb_scheduled_events",
                ['order_id' => $order_number],
                ['start_time' => $event_start],
                ['%d'],
                ['%s']
            );
        }
    }
});

add_filter('template_include', function($template) {
    $page_id = get_option( \Calendly_Bookings\CB_Installer::get_page_option() ); 
    
    // If this is the meeting-scheduled page, force plugin template 
    if ($page_id && is_page($page_id)) {
        return plugin_dir_path(__FILE__) . '../templates/meeting-scheduled.php';
    }
    
    return $template;
});




