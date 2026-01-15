<?php
//includes/modules/class-cb-checkout.php
namespace Calendly_Bookings\Modules;

use WC_Order;

class CB_Checkout {

    public static function register(): void {
        // Checkout fields
        add_action('woocommerce_after_order_notes', [__CLASS__, 'add_checkout_fields']);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_order_meta'], 10, 2);

        // Prefill checkout from cart
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'capture_form_data'], 10, 3);
        add_filter('woocommerce_checkout_get_value', [__CLASS__, 'prefill_checkout'], 10, 2);
        
        //create new Account
        add_action('woocommerce_payment_complete', [__CLASS__, 'attach_order_to_account']);

        // Display in emails, My Account, admin
        add_action('woocommerce_email_order_meta', [__CLASS__, 'add_to_emails'], 10, 4);
        add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'add_to_my_account']);
        add_filter('manage_edit-shop_order_columns', [__CLASS__, 'add_admin_column']);
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'render_admin_column'], 10, 2);
        
        // Override Thank You page
        add_action('template_redirect', [__CLASS__, 'maybe_override_thankyou']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_calendly_embed']);
    }

	public static function add_checkout_fields($checkout) {
		echo '<input type="hidden" name="cb_meeting_location" value="' . esc_attr($checkout->get_value('cb_meeting_location')) . '" />';
		echo '<input type="hidden" name="cb_meeting_date" value="' . esc_attr($checkout->get_value('cb_meeting_date')) . '" />';
		echo '<input type="hidden" name="cb_meeting_time" value="' . esc_attr($checkout->get_value('cb_meeting_time')) . '" />';
		echo '<input type="hidden" name="cb_hier_intro" value="' . esc_attr($checkout->get_value('cb_hier_intro')) . '" />';
	}

    public static function capture_form_data($cart_item_data, $product_id, $variation_id) {
        $fields = [
            'cb_meeting_location' => FILTER_SANITIZE_STRING,
            'cb_meeting_date' => FILTER_SANITIZE_STRING,
            'cb_meeting_time' => FILTER_SANITIZE_STRING,
            'cb_hier_intro' => FILTER_SANITIZE_STRING,
            'order_comments'  => FILTER_SANITIZE_STRING,
        ];
        foreach ($fields as $key => $filter) {
            if (!empty($_POST[$key])) {
                $cart_item_data[$key] = filter_var(wp_unslash($_POST[$key]), $filter);
            }
        }

		CB_Audit_Log::log('capture_form_data', 'checkout', (string)$product_id, $cart_item_data, 'info');
        return $cart_item_data;
    }

    public static function prefill_checkout($value, $input) {
        $cart = WC()->cart;
        if (!$cart) return $value;
        foreach ($cart->get_cart() as $item) {
            if (isset($item[$input])) {
                return $item[$input];
            }
        }
        return $value;
    }

	public static function save_order_meta(\WC_Order $order, $data) {
		// Meeting location (hidden field)
		if (!empty($_POST['cb_meeting_location'])) {
			$order->update_meta_data(
				'_cb_meeting_location',
				sanitize_text_field(wp_unslash($_POST['cb_meeting_location']))
			);
		}

		// Meeting date (hidden field)
		if (!empty($_POST['cb_meeting_date'])) {
			$order->update_meta_data(
				'_cb_meeting_date',
				sanitize_text_field(wp_unslash($_POST['cb_meeting_date']))
			);
		}

		// Meeting time (hidden field)
		if (!empty($_POST['cb_meeting_time'])) {
			$order->update_meta_data(
				'_cb_meeting_time',
				sanitize_text_field(wp_unslash($_POST['cb_meeting_time']))
			);
		}

		// Meeting intro question (hidden field)
		if (!empty($_POST['cb_hier_intro'])) {
			$order->update_meta_data(
				'_cb_hier_intro',
				sanitize_text_field(wp_unslash($_POST['cb_hier_intro']))
			);
		}

		// Meeting notes: persist "Nil" if empty
		$notes = !empty($_POST['order_comments'])
			? sanitize_textarea_field(wp_unslash($_POST['order_comments']))
			: 'Nil';

		$order->update_meta_data('_cb_meeting_notes', $notes);

		// Do not set customer note if Nil (prevents it showing in emails/invoices)
		if ($notes !== 'Nil') {
			$order->set_customer_note($notes);
		}
		
		CB_Audit_Log::log('save_order_meta', 'checkout', (string)$order->get_id(), [
			'location' => $_POST['cb_meeting_location'] ?? '',
			'date'     => $_POST['cb_meeting_date'] ?? '',
			'time'     => $_POST['cb_meeting_time'] ?? '',
			'intro'    => $_POST['cb_hier_intro'] ?? '',
			'notes'    => $notes,
		], 'info');

	}
	
	/**
     * Ensure a customer account exists and attach the order.
     * If new, send activation email.
     *
     * @param string $name     Full name of the customer.
     * @param string $email    Email address of the customer.
     * @param int    $order_id WooCommerce order ID.
     * @return int|WP_Error    User ID or error.
     */
    public static function attach_order_to_account() {
        if( is_user_logged_in() ) {
            return;
        }
        
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
    
        $name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $email = $order->get_billing_email();
        
    
        $user = get_user_by( 'email', $email );
    
        if ( ! $user ) {
            // Split name into first/last
            $parts = explode( ' ', $name, 2 );
            $first = $parts[0] ?? '';
            $last  = $parts[1] ?? '';
    
            // Generate a unique username from email
            $username = sanitize_user( current( explode( '@', $email ) ), true );
            if ( username_exists( $username ) ) {
                $username .= '_' . wp_generate_password( 4, false );
            }
    
            // Create user with a random password
            $password = wp_generate_password();
            $user_id  = wp_create_user( $username, $password, $email );
            if ( is_wp_error( $user_id ) ) {
                return $user_id;
            }
    
            // Update profile fields
            wp_update_user( [
                'ID'           => $user_id,
                'first_name'   => $first,
                'last_name'    => $last,
                'display_name' => $name,
            ] );
    
            $user = get_user_by( 'id', $user_id );
    
            // Send activation email (using WP built-in new user notification)
            wp_new_user_notification( $user_id, null, 'user' );
        }
    
        // Attach order to user (WooCommerce)
        if ( function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->set_customer_id( $user->ID );
                $order->save();
            }
        }
    
        return $user->ID;
    }


    public static function add_to_emails($order, $sent_to_admin, $plain_text, $email) {
		$date  = $order->get_meta('_cb_meeting_date');
		$time  = $order->get_meta('_cb_meeting_time');
		$location = $order->get_meta('_cb_meeting_location');
		$intro = $order->get_meta('_cb_hier_intro');
		$notes = $order->get_meta('_cb_meeting_notes');

		// Skip if all empty or notes == Nil
		if (!$date && !$time && (!$notes || $notes === 'Nil')) return;

		if ($plain_text) {
			echo "\n" . __('Meeting Details', 'calendly-bookings') . "\n";
			echo "--------------------------\n";
			if ($date) echo __('Date:', 'calendly-bookings') . ' ' . $date . "\n";
			if ($time) echo __('Time:', 'calendly-bookings') . ' ' . $time . "\n";
			if ($notes && $notes !== 'Nil') echo __('Notes:', 'calendly-bookings') . ' ' . $notes . "\n";
		} else {
			echo '<h3>' . esc_html__('Meeting Details', 'calendly-bookings') . '</h3><ul>';
			if ($date) echo '<li><strong>' . esc_html__('Date:', 'calendly-bookings') . '</strong> ' . esc_html($date) . '</li>';
			if ($time) echo '<li><strong>' . esc_html__('Time:', 'calendly-bookings') . '</strong> ' . esc_html($time) . '</li>';
			if ($intro) echo '<li><strong>' . esc_html__('Initial introduction:', 'calendly-bookings') . '</strong> ' . esc_html($intro) . '</li>';
			if ($notes && $notes !== 'Nil') {
				echo '<li><strong>' . esc_html__('Notes:', 'calendly-bookings') . '</strong> ' . nl2br(esc_html($notes)) . '</li>';
			}
			echo '</ul>';
		}
		
		CB_Audit_Log::log('add_to_emails', 'checkout', (string)$order->get_id(), [
			'date' => $date,
			'time' => $time,
			'location' => $location,
			'introduction' => $intro,
		], 'info');

	}

    public static function add_to_my_account($order) {
        $date  = $order->get_meta('_cb_meeting_date');
        $time  = $order->get_meta('_cb_meeting_time');
		$location = $order->get_meta('_cb_meeting_location');
		$intro = $order->get_meta('_cb_hier_intro');
        $notes = $order->get_customer_note();

        if (!$date && !$time && !$notes) return;

        echo '<section class="woocommerce-order-details">';
        echo '<h2>' . esc_html__('Meeting Details', 'calendly-bookings') . '</h2>';
        echo '<table class="woocommerce-table shop_table meeting_details"><tbody>';
        if ($date) echo '<tr><th>' . esc_html__('Date', 'calendly-bookings') . '</th><td>' . esc_html($date) . '</td></tr>';
        if ($time) echo '<tr><th>' . esc_html__('Time', 'calendly-bookings') . '</th><td>' . esc_html($time) . '</td></tr>';
        if ($location) echo '<tr><th>' . esc_html__('Location', 'calendly-bookings') . '</th><td>' . esc_html($location) . '</td></tr>';
		if ($intro) echo '<tr><th>' . esc_html__('Initial introduction:', 'calendly-bookings') . '</th><td>' . esc_html($intro) . '</td></tr>';
        if ($notes) echo '<tr><th>' . esc_html__('Notes', 'calendly-bookings') . '</th><td>' . nl2br(esc_html($notes)) . '</td></tr>';
        echo '</tbody></table></section>';
		
		CB_Audit_Log::log('add_to_my_account', 'checkout', (string)$order->get_id(), [
			'date' => $date,
			'time' => $time,
			'location' => $location,
			'introduction' => $intro,
		], 'info');

    }

    public static function add_admin_column($columns) {
        $columns['cb_meeting'] = __('Meeting', 'calendly-bookings');
        return $columns;
    }

    public static function render_admin_column($column, $post_id) {
        if ($column === 'cb_meeting') {
            $order = wc_get_order($post_id);
            $date  = $order->get_meta('_cb_meeting_date');
            $time  = $order->get_meta('_cb_meeting_time');
			$location = $order->get_meta('_cb_meeting_location');
            echo $date || $time ? esc_html(trim("$date $time")) : '—';
        }
		
		CB_Audit_Log::log('render_admin_column', 'checkout', (string)$post_id, [
			'date' => $date,
			'time' => $time,
		], 'info');

    }

    public static function maybe_override_thankyou() {
        if (!is_order_received_page()) return;

        $order_id = absint(get_query_var('order-received'));
        $order    = wc_get_order($order_id);

        if ($order && self::order_has_meeting($order)) {
    		remove_all_actions('woocommerce_thankyou');
			remove_action('woocommerce_thankyou', 'woocommerce_thankyou_order_received_text', 10);remove_all_actions('woocommerce_order_details_after_order_table');            add_action('woocommerce_thankyou', [__CLASS__, 'render_meeting_thankyou'], 10, 1);

			CB_Audit_Log::log('override_thankyou', 'checkout', (string)$order_id, [], 'info');
        }	
    }

	public static function render_meeting_thankyou($order_id) {
		$order = wc_get_order($order_id);
		if (!$order) return;

		$date  = $order->get_meta('_cb_meeting_date');
		$time  = $order->get_meta('_cb_meeting_time');
		$location = $order->get_meta('_cb_meeting_location');
		$intro = $order->get_meta('_cb_hier_intro');
		$notes = empty($order->get_customer_note())?"Nil":$order->get_customer_note();

		// Collect scheduling URLs from products in the order
		$meeting_links = [];
		foreach ($order->get_items() as $item) {
			$product_id = $item->get_product_id();
			if (!$product_id) continue;

			$url = get_post_meta($product_id, '_cb_scheduling_url', true) . '/' . $time;
			if ($url) {
				$meeting_links[] = [
					'product_name'   => $item->get_name(),
					'scheduling_url' => $url,
				];
			}
		}
		$scheduling_url = '';
		if (!empty($meeting_links)) {
			$base_url = $meeting_links[0]['scheduling_url'];
			$scheduling_url = $time ? trailingslashit(rawurldecode($base_url)) : $base_url;
		}
		// Shared params for Calendly prefill
		$params = [
			'name'   => $order->get_formatted_billing_full_name(),
			'email'  => $order->get_billing_email(),
			'location' => $location,
			'a1'     => $order->get_order_number(),
			'a2'     => $intro,
		];

	 ?>

		<!-- Calendly embed -->
		<div id="calendly-wrapper" style="margin-top:2rem;">
				<?php echo esc_html__('Please confirm the details below before scheduling this event.', 'calendly-bookings'); ?>
			<div id="calendly-embed"></div>
		</div>

		<script>
		document.addEventListener("DOMContentLoaded", function() {
			var wrapper = document.getElementById("calendly-wrapper");
			var embed   = document.getElementById("calendly-embed");
			var params  = <?php echo wp_json_encode($params); ?>;

			var schedulingUrl = "<?php echo $scheduling_url; ?>";

			var url = schedulingUrl +
				"?hide_event_type_details=1&hide_gdpr_banner=1&name="  + encodeURIComponent(params.name) +
				"&email=" + encodeURIComponent(params.email) +
				"&location=" + encodeURIComponent(params.location) +
				"&a1="    + encodeURIComponent(params.a1) +
				"&a2="    + encodeURIComponent(params.a2);

			wrapper.style.display = "block";
			wrapper.scrollIntoView({ behavior: "smooth" });

			Calendly.initInlineWidget({
				url: url,
				text: 'Confirm Booking',
				parentElement: embed,
				prefill: {
					name: params.name,
					email: params.email,
					location:params.location,
					customAnswers: {
						a1: params.a1,
						a2: params.a2
					}
				},
				utm:{},
				resize: true,									
			});
		});
		</script>
		<?php
		CB_Audit_Log::log('render_meeting_thankyou', 'checkout', (string)$order_id, $params, 'info');
	}
	
	public static function enqueue_calendly_embed() {
		wp_enqueue_script(
			'calendly-widget',
			'https://assets.calendly.com/assets/external/widget.js',
			array(),
			null,
			true // load in footer
		);
	}
	
	public static function order_has_meeting($order): bool {
		if (!$order) {
			error_log('[CB_Checkout] No order object passed to order_has_meeting().');
			CB_Audit_Log::log('order_check_failed', 'checkout', '', ['reason' => 'no_order_object'], 'error');
			return false;
		}

		foreach ($order->get_items() as $item_id => $item) {
			$product = $item->get_product();
			if (!$product) {
				error_log("[CB_Checkout] Item {$item_id} has no product.");
				CB_Audit_Log::log('order_item_missing_product', 'checkout', (string)$item_id, [], 'warning');
				continue;
			}

			$product_id = $product->get_id();
			$parent_id  = $product->is_type('variation') ? $product->get_parent_id() : $product_id;

			$uuid        = get_post_meta($product_id, '_cb_event_uuid', true);
			$parent_uuid = $parent_id ? get_post_meta($parent_id, '_cb_event_uuid', true) : '';

			error_log("[CB_Checkout] Checking product {$product_id} (parent {$parent_id}) — UUID: {$uuid} | Parent UUID: {$parent_uuid}");
			CB_Audit_Log::log('order_item_checked', 'checkout', (string)$product_id, [
				'parent_id'   => $parent_id,
				'uuid'        => $uuid,
				'parent_uuid' => $parent_uuid,
			], 'info');

			if (!empty($uuid) || !empty($parent_uuid)) {
				error_log("[CB_Checkout] Meeting product detected for order " . $order->get_id());
				CB_Audit_Log::log('meeting_product_detected', 'checkout', (string)$order->get_id(), [
					'product_id'   => $product_id,
					'parent_id'    => $parent_id,
					'uuid'         => $uuid,
					'parent_uuid'  => $parent_uuid,
				], 'info');
				return true;
			}
		}

		error_log("[CB_Checkout] No meeting products found for order " . $order->get_id());
		CB_Audit_Log::log('no_meeting_product', 'checkout', (string)$order->get_id(), [], 'info');
		return false;
	}
}