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
		
		$data = [
			'root'    => trailingslashit( rest_url( 'calendly-bookings/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'product' => $product_id,
			'uuid'    => $event_uuid,
		];
		wp_add_inline_script(
			'cb-frontend',
			'const CB_REST = ' . wp_json_encode( $data ) . ';',
			'before'
		);

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

	<!-- 📍 Location radio group -->
	<div class="cb-field-row">
		<div class="cb-field">
			<label for="cb_meeting_location"><?php esc_html_e('Location', 'calendly-bookings'); ?></label>
			<select id="cb_meeting_location" name="cb_meeting_location" required>
				<option value=""><?php esc_html_e('Select a location', 'calendly-bookings'); ?></option>
				<option value="1">
					<?php esc_html_e('Zoom - Web conferencing details provided upon confirmation.', 'calendly-bookings'); ?>
				</option>
				<option value="2">
					<?php esc_html_e("HIER Life - Skeete's Road Jackmans, St. Michael", 'calendly-bookings'); ?>
				</option>
			</select>
		</div>
	</div>
	<!-- end location -->

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

	<div class="cb-field">
			<label for="cb_hier_intro"><?php esc_html_e('How did you hear about HIER Life?', 'calendly-bookings'); ?></label>
			<select id="cb_hier_intro" name="cb_hier_intro" required>
				<option value=""><?php esc_html_e('Select...', 'calendly-bookings'); ?></option>
				<option value="<?php esc_html_e('Google Search', 'calendly-bookings'); ?>"><?php esc_html_e('Google Search', 'calendly-bookings'); ?></option>
				<option value="<?php esc_html_e('Word of mouth', 'calendly-bookings'); ?>"><?php esc_html_e('Word of mouth', 'calendly-bookings'); ?></option>
				<option value="<?php esc_html_e('Referred by a professional', 'calendly-bookings'); ?>"><?php esc_html_e('Referred by a professional', 'calendly-bookings'); ?></option>
				<option value="<?php esc_html_e('Spoke with Michael directly', 'calendly-bookings'); ?>"><?php esc_html_e('Spoke with Michael directly', 'calendly-bookings'); ?></option>
				<option value="<?php esc_html_e('Social Media', 'calendly-bookings'); ?>"><?php esc_html_e('Social Media', 'calendly-bookings'); ?></option>
			</select>

	</div>

	<div class="cb-field-row">
		<div class="cb-field">
			<label for="cb_notes"><?php esc_html_e('Notes', 'calendly-bookings'); ?></label>
			<div class="has-small-font-size">
				<?php esc_html_e('As you prepare for our first meeting please share anything that you believe would be important for me to know about you that would assist us in making the most of this first session.', 'calendly-bookings'); ?>
			</div>
			<textarea id="cb_notes" name="order_comments" rows="4" placeholder="<?php esc_attr_e('Enter notes or leave blank for Nil', 'calendly-bookings'); ?>"></textarea>
		</div>
	</div>

	<input type="hidden" name="cb_prefill" value="1">
		<?php
		return ob_get_clean();
	}
   
}