<?php
namespace Calendly_Bookings\Modules;
use Calendly_Bookings\CB_Constants;

if (!defined('ABSPATH')) exit;


final class CB_Frontend {

    public static function init() {
        add_shortcode('calendly_booking_form', [__CLASS__, 'render_calendly_form']);
        add_action('woocommerce_before_add_to_cart_button', [__CLASS__, 'output_before_cart']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets() {
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
		
		wp_localize_script('cb-frontend', 'CB_REST', trailingslashit(rest_url('calendly-bookings/v1/')));
		wp_localize_script('cb-frontend', 'CB_REST_NONCE', wp_create_nonce('wp_rest'));
		wp_localize_script('cb-frontend', 'CB_REST_PRODUCT', $product_id);
		wp_localize_script('cb-frontend', 'CB_REST_UUID', $event_uuid);

		wp_enqueue_style(
            'cb-frontend',
            CB_Constants::url('includes/frontend/assets/cb-frontend.css'),
            [],
            CB_Constants::VERSION
        );

    }
    public static function output_before_cart() {
        echo self::render_calendly_form();
    }
    public static function render_calendly_form($atts = []) {
        global $product;
		ob_start();
        ?>
<div class="cb-field-row">
    <div class="cb-field half">
        <label for="cb_firstname"><?php esc_html_e('First Name', 'calendly-bookings'); ?></label>
        <input type="text" id="cb_firstname" name="billing_first_name" required>
    </div>

    <div class="cb-field half">
        <label for="cb_lastname"><?php esc_html_e('Last Name', 'calendly-bookings'); ?></label>
        <input type="text" id="cb_lastname" name="billing_last_name" required>
    </div>
</div>
            <div class="cb-field">
                <label for="cb_email"><?php esc_html_e('Email', 'calendly-bookings'); ?></label>
                <input type="email" id="cb_email" name="billing_email" required>
            </div>

<div class="cb-field-row">
    <div class="cb-field half">
        <label for="cb_meeting_date"><?php esc_html_e('Meeting Date', 'calendly-bookings'); ?></label>
        <select id="cb_meeting_date" name="cb_meeting_date" required>
            <option value=""><?php esc_html_e('Select a date', 'calendly-bookings'); ?></option>
        </select>
    </div>

    <div class="cb-field half">
        <label for="cb_meeting_time"><?php esc_html_e('Meeting Time', 'calendly-bookings'); ?></label>
        <select id="cb_meeting_time" name="cb_meeting_time" required>
            <option value=""><?php esc_html_e('Select a time', 'calendly-bookings'); ?></option>
        </select>
    </div>
</div>

<div class="cb-field-row">
	<div class="cb-field">
		<label for="cb_notes"><?php esc_html_e('Notes', 'calendly-bookings'); ?></label>
		<div class="has-small-font-size">As you prepare for our first meeting please share anything that you believe would be important for me to know about you that would assist us in making the most of this first session.</div>
		<textarea id="cb_notes" name="order_comments" rows="4" placeholder="<?php esc_attr_e('Enter notes or leave blank for Nil', 'calendly-bookings'); ?>"></textarea>
	</div>
</div>

            <input type="hidden" name="cb_prefill" value="1">
        <?php
        return ob_get_clean();
    }
    
}