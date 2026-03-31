<?php
namespace Calendly_Bookings\Modules;
use Calendly_Bookings\CB_Constants;

if (!defined('ABSPATH')) exit;

final class CB_Frontend {

    public static function init() {
        CB_Audit_Log::log('method_entry', 'frontend', __METHOD__, [], 'info');
        try {
            add_shortcode('calendly_booking_form', [__CLASS__, 'render_calendly_form']);
            add_action('woocommerce_single_product_summary', [__CLASS__, 'cb_insert_after_title' ], 4);
            add_action('woocommerce_before_add_to_cart_button', [__CLASS__, 'output_before_cart']);
            add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
            add_action('wp_ajax_cb_login', [__CLASS__, 'cb_ajax_login']);
            add_action('wp_ajax_nopriv_cb_login', [__CLASS__, 'cb_ajax_login']);
            CB_Audit_Log::log('method_exit', 'frontend', __METHOD__, [], 'info');
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'frontend', __METHOD__, ['error' => $e->getMessage()], 'error');
        }
    }

    public static function cb_ajax_login() {
        CB_Audit_Log::log('method_entry', 'frontend', __METHOD__, [], 'info');
        try {
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
            CB_Audit_Log::log('method_exit', 'frontend', __METHOD__, [], 'info');
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'frontend', __METHOD__, ['error' => $e->getMessage()], 'error');
        }
    }

    public static function enqueue_assets() {
        CB_Audit_Log::log('method_entry', 'frontend', __METHOD__, [], 'info');
        try {
    		global $product;

    		// Default values
    		$product_id = 0;
    		$event_uuid = '';

    		if ($product instanceof \WC_Product) {
    			$product_id = $product->get_id();
    			$event_uuid = get_post_meta($product_id, '_cb_event_uuid', true);
    		} elseif (is_singular('product')) {
    			$product_id = get_the_ID();
    			$event_uuid = get_post_meta($product_id, '_cb_event_uuid', true);
    		}

            wp_enqueue_script(
                'cb-frontend',
                CB_Constants::url('includes/frontend/assets/cb-frontend.js'),
                ['jquery'],
                CB_Constants::VERSION,
                true
            );

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

            wp_enqueue_style(
                'cb-frontend',
                CB_Constants::url('includes/frontend/assets/cb-frontend.css'),
                [],
                CB_Constants::VERSION
            );



            wp_localize_script(
                'cb-frontend',
                'cb_ajax_object',
                array( 'ajaxurl' => admin_url('admin-ajax.php')
                )
            );



            CB_Audit_Log::log('method_exit', 'frontend', __METHOD__, [], 'info');
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'frontend', __METHOD__, ['error' => $e->getMessage()], 'error');
        }
    }

    public static function output_before_cart() {
        CB_Audit_Log::log('method_entry', 'frontend', __METHOD__, [], 'info');
        try {
            echo self::render_calendly_form();
            CB_Audit_Log::log('method_exit', 'frontend', __METHOD__, [], 'info');
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'frontend', __METHOD__, ['error' => $e->getMessage()], 'error');
        }
    }

    public static function render_calendly_form($atts = []) {
        CB_Audit_Log::log('method_entry', 'frontend', __METHOD__, ['atts' => $atts], 'info');
        try {
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
            CB_Audit_Log::log('method_exit', 'frontend', __METHOD__, ['output_length' => strlen($output)], 'info');
            return $output;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'frontend', __METHOD__, ['error' => $e->getMessage(), 'atts' => $atts], 'error');
            throw $e;
        }
    }

    public static function cb_insert_after_title() {
        CB_Audit_Log::log('method_entry', 'frontend', __METHOD__, [], 'info');
        try {
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
            CB_Audit_Log::log('method_exit', 'frontend', __METHOD__, [], 'info');
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'frontend', __METHOD__, ['error' => $e->getMessage()], 'error');
        }
    }
}
