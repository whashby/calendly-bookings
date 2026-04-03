<?php
namespace Calendly_Bookings\Frontend\View;

use WC_Product;
use Calendly_Bookings\Utils\CB_Encryption;

global $product, $context;

/**
 * 1. Validation notice: account exists but not logged in
 */
if (!empty($context['account_exists']) && empty($context['logged_in'])) {
    echo '<p class="cb-notice error">' . esc_html__(
        'An account exists for this email. Please log in before continuing.',
        'calendly-bookings'
    ) . '</p>';
    return;
}

/**
 * 2. Product logic
 */
if (is_product() && $product instanceof WC_Product) {

    // Decode follow-up token if present
    $followup_data = [];
    if (!empty($_GET['token'])) {
        $decoded = CB_Encryption::decrypt(sanitize_text_field($_GET['token']));
        $followup_data = json_decode($decoded, true) ?: [];
        wp_localize_script('cb-frontend', 'CB_FOLLOWUP', $followup_data);
    }

    // Localize site timezone and REST data for frontend JS
    wp_localize_script('cb-frontend', 'CB_REST', [
        'site_timezone' => get_option('timezone_string') ?: 'America/Barbados',
        'uuid'          => $product->get_id(),
        'root'          => esc_url(rest_url('calendly-bookings/v1/')),
        'nonce'         => wp_create_nonce('wp_rest')
    ]);

    // Handle initial consultation redirect logic
    if ($product->get_slug() === 'initial-consultation' && is_user_logged_in()) {
        $user_id = get_current_user_id();
        $has_meeting_purchase = false;

        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status'      => ['completed', 'processing'],
            'limit'       => -1,
        ]);

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $item_product = $item->get_product();
                if ($item_product) {
                    $categories = wp_get_post_terms(
                        $item_product->get_id(),
                        'product_cat',
                        ['fields' => 'slugs']
                    );
                    if (array_intersect($categories, ['meeting', 'meetings'])) {
                        $has_meeting_purchase = true;
                        break 2;
                    }
                }
            }
        }

        if ($has_meeting_purchase) {
            $uri = cb_get_product_permalink_by_slug('spiritual-companionship');
            wp_safe_redirect($uri . '?ref=' . base64_encode('initial-consultation'));
            exit;
        } else {
            include __DIR__ . '/form.php';
        }

    } else {
        include __DIR__ . '/form.php';
    }
}

/**
 * Helper: Get product permalink by slug
 */
function cb_get_product_permalink_by_slug($slug) {
    $product = get_page_by_path($slug, OBJECT, 'product');
    return $product ? get_permalink($product->ID) : '';
}
