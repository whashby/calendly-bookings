<?php

namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;

final class CB_Frontend {

    public static function init() {
        add_shortcode('calendly_booking_form', [__CLASS__, 'render_calendly_form']);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'cb_insert_after_title' ], 4);
        add_action('woocommerce_before_add_to_cart_button', [__CLASS__, 'output_before_cart']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'cb_enqueue_flatpickr_assets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_cb_login', [__CLASS__, 'cb_ajax_login']);
        add_action('wp_ajax_nopriv_cb_login', [__CLASS__, 'cb_ajax_login']);
    }

    public static function cb_ajax_login() {
        $creds = [
            'user_login'    => sanitize_text_field($_POST['log']),
            'user_password' => sanitize_text_field($_POST['pwd']),
            'remember'      => true,
        ];
        $user = wp_signon($creds, false);

        if (is_wp_error($user)) {
            wp_send_json_error(['message' => $user->get_error_message()]);
        } else {
            wp_send_json_success(['redirect' => $_POST['redirect_to'] ?? home_url()]);
        }
    }

    public static function cb_enqueue_flatpickr_assets(): void {
        // Flatpickr CSS
        wp_enqueue_style(
            'flatpickr-css',
            'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
            [],
            '4.6.13'
        );

        // Flatpickr JS
        wp_enqueue_script(
            'flatpickr-js',
            'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js',
            ['jquery'],
            '4.6.13',
            true
        );
    }

    public static function enqueue_assets(): void {
        global $product;

        // Default values
        $product_id = 0;
        $event_uuid = '';

if (is_singular('product')) {
    $product_id = get_the_ID();
    $product    = wc_get_product($product_id);

    // Only proceed if product exists and is in the right categories
    if ($product && has_term(['meeting', 'meetings'], 'product_cat', $product_id)) {
        // Retrieve UUID
        $event_uuid = get_post_meta($product_id, '_cb_event_uuid', true);

        // Enqueue scripts/styles
        wp_enqueue_script(
            'cb-frontend',
            CB_Constants::url('includes/frontend/assets/cb-frontend.js'),
            ['jquery'],
            CB_Constants::VERSION,
            true
        );

        wp_enqueue_style(
            'cb-frontend',
            CB_Constants::url('includes/frontend/assets/cb-frontend.css'),
            [],
            CB_Constants::VERSION
        );
    }
}

        $messages = include CB_Constants::path('includes/frontend/view/validation-messages.php');

        $data = [
            'root'  => trailingslashit(rest_url('calendly-bookings/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'product' => $product_id,
            'uuid'    => $event_uuid,
        ];

        wp_add_inline_script(
            'cb-frontend',
            'const CB_REST = ' . wp_json_encode($data) . '; const CB_MESSAGES = ' . wp_json_encode($messages) . ';',
            'before'
        );

        wp_localize_script(
            'cb-frontend',
            'cb_ajax_object',
            array( 'ajaxurl' => admin_url('admin-ajax.php')
            )
        );
    }


    
    public static function output_before_cart(): void {
            echo self::render_calendly_form();
    }




    public static function render_calendly_form($atts = []): string {
        $context = [
            'account_exists'   => false,
            'logged_in'        => is_user_logged_in(),
            'has_meeting_order'=> false,
        ];

        if ($context['logged_in']) {
            $orders = wc_get_orders([
                'customer_id' => get_current_user_id(),
                'status'      => ['completed', 'processing'],
            ]);
            foreach ($orders as $order) {
                foreach ($order->get_items() as $item) {
                    if (stripos($item->get_name(), 'meeting') !== false) {
                        $context['has_meeting_order'] = true;
                        break 2;
                    }
                }
            }
        }

        ob_start();
        include CB_Constants::path('includes/frontend/view/index.php');
        $output = ob_get_clean();
        return $output;
    }

    public static function cb_insert_after_title(): void {
        if ( isset($_GET['ref']) && ! empty($_GET['ref'])):
            $ref = ucwords(implode(' ', explode('-', base64_decode($_GET['ref']))));
        ?>
        <div class="cb-upsell">
            <p><em>
                <?php printf(
                esc_html__('%ss are not available again. We recommend booking a Spiritual Companionship session instead.', 'calendly-bookings'),
                esc_html($ref)
                ); ?>
            </em></p>
                    </div>
        <?php endif;

        if ( ! is_user_logged_in() ) {
            include CB_Constants::path('includes/frontend/view/login-modal.php');
        }
    }

    
    
    /**
     * Determine if the current product belongs to the "meeting" or "meetings" category.
     *
     * @param WC_Product|int|null $product Product object, product ID, or null (defaults to current global post).
     * @return bool
     */
    public static function is_meeting_product($product = null): bool {
        // If no product passed, try to get from global $post
        if ($product === null) {
            global $post;
            if (!$post || $post->post_type !== 'product') {
                return false;
            }
            $product = wc_get_product($post->ID);
        } elseif (is_numeric($product)) {
            $product = wc_get_product($product);
        }
        if (!$product instanceof WC_Product) {
            return false;
        }
    
        // Get product category slugs
        $categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'slugs']);
    
        // Return true if "meeting" or "meetings" is present
        return in_array('meeting', $categories, true) || in_array('meetings', $categories, true);
    }

}
