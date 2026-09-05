<?php
//includes/modules/class-cb-checkout.php
namespace Calendly_Bookings\Modules;

use WC_Order;
use Calendly_Bookings\CB_Constants;
use Calendly_Bookings\Utils\CB_Timezone_Converter;

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
        #add_action('woocommerce_payment_complete', [__CLASS__, 'create_calendly_invitee']);

        // Display in emails, My Account, admin
        add_action('woocommerce_email_order_meta', [__CLASS__, 'add_to_emails'], 10, 4);
        add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'add_to_my_account']);
        add_filter('manage_edit-shop_order_columns', [__CLASS__, 'add_admin_column']);
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'render_admin_column'], 10, 2);
        
        // Override Thank You page
        add_action('template_redirect', [__CLASS__, 'maybe_override_thankyou'], 1);
		add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_calendly_embed']);
    }

public static function add_checkout_fields($checkout) {
    // Common fields
    echo '<input type="hidden" name="cb_meeting_location" value="' . esc_attr($checkout->get_value('cb_meeting_location')) . '" />';
    echo '<input type="hidden" name="cb_meeting_date" value="' . esc_attr($checkout->get_value('cb_meeting_date')) . '" />';
    echo '<input type="hidden" name="cb_meeting_time" value="' . esc_attr($checkout->get_value('cb_meeting_time')) . '" />';

    // Initial Consultation
    echo '<input type="hidden" name="cb_hier_intro" value="' . esc_attr($checkout->get_value('cb_hier_intro')) . '" />';

    // Meditation Session
    echo '<input type="hidden" name="cb_prep_notes" value="' . esc_attr($checkout->get_value('cb_prep_notes')) . '" />';
    echo '<input type="hidden" name="cb_new_practice" value="' . esc_attr($checkout->get_value('cb_new_practice')) . '" />';
    echo '<input type="hidden" name="cb_methods" value="' . esc_attr($checkout->get_value('cb_methods')) . '" />';
    $familiarity = $checkout->get_value('cb_familiarity');
    if (!empty($familiarity) && is_array($familiarity)) {
        echo '<input type="hidden" name="cb_familiarity" value="' . esc_attr(implode(',', $familiarity)) . '" />';
    }
    echo '<input type="hidden" name="cb_other_text" value="' . esc_attr($checkout->get_value('cb_other_text')) . '" />';

    // Spiritual Companionship
    echo '<input type="hidden" name="cb_experience" value="' . esc_attr($checkout->get_value('cb_experience')) . '" />';

    // QHHT Session
    echo '<input type="hidden" name="cb_qhht_questions" value="' . esc_attr($checkout->get_value('cb_qhht_questions')) . '" />';
}
    
public static function capture_form_data($cart_item_data, $product_id, $variation_id) {
    $fields = [
        // Common
        'cb_meeting_location' => 'sanitize_text_field',
        'cb_meeting_date'     => 'sanitize_text_field',
        'cb_meeting_time'     => 'sanitize_text_field',
        'cb_hier_intro'       => 'sanitize_textarea_field',
        'order_comments'      => 'sanitize_textarea_field',

        // Meditation
        'cb_prep_notes'       => 'sanitize_textarea_field',
        'cb_new_practice'     => 'sanitize_text_field',
        'cb_methods'          => 'sanitize_text_field',
        'cb_familiarity'      => function($val) {
            return array_map('sanitize_text_field', (array)$val);
        },
        'cb_other_text'       => 'sanitize_text_field',

        // Spiritual Companionship
        'cb_experience'       => 'sanitize_textarea_field',

        // QHHT
        'cb_qhht_questions'   => 'sanitize_textarea_field',
    ];

    foreach ($fields as $key => $callback) {
        if (!empty($_POST[$key])) {
            $raw_value = wp_unslash($_POST[$key]);
            if (is_callable($callback)) {
                $cart_item_data[$key] = call_user_func($callback, $raw_value);
            } else {
                $cart_item_data[$key] = $callback($raw_value);
            }
        }
    }
    return $cart_item_data;
}
public static function prefill_checkout($value, $input) {
    $cart = WC()->cart;
    if (!$cart) {
        return $value;
    }

    foreach ($cart->get_cart() as $item) {
        if (isset($item[$input])) {
            return $item[$input];
        }
    }

    // Fallback: check posted data (if coming directly from form)
    if (!empty($_POST[$input])) {
        return sanitize_text_field(wp_unslash($_POST[$input]));
    }

    return $value;
}

public static function save_order_meta(\WC_Order $order, $data) {
    // Generate and store a security token
    $token = wp_generate_uuid4();
    $order->update_meta_data('_cb_security_token', $token);

    // Meeting location
    if (!empty($_POST['cb_meeting_location'])) {
        $order->update_meta_data(
            '_cb_meeting_location',
            sanitize_text_field(wp_unslash($_POST['cb_meeting_location']))
        );
    }

    // Meeting date
    if (!empty($_POST['cb_meeting_date'])) {
        $order->update_meta_data(
            '_cb_meeting_date',
            sanitize_text_field(wp_unslash($_POST['cb_meeting_date']))
        );
    }

    // Meeting time
    if (!empty($_POST['cb_meeting_time'])) {
        $order->update_meta_data(
            '_cb_meeting_time',
            sanitize_text_field(wp_unslash($_POST['cb_meeting_time']))
        );
    }

    // Initial consultation intro
    if (!empty($_POST['cb_hier_intro'])) {
        $order->update_meta_data(
            '_cb_hier_intro',
            sanitize_textarea_field(wp_unslash($_POST['cb_hier_intro']))
        );
    }

    // Meditation prep notes
    if (!empty($_POST['cb_prep_notes'])) {
        $order->update_meta_data(
            '_cb_prep_notes',
            sanitize_textarea_field(wp_unslash($_POST['cb_prep_notes']))
        );
    }

    // Meditation new practice
    if (!empty($_POST['cb_new_practice'])) {
        $order->update_meta_data(
            '_cb_new_practice',
            sanitize_text_field(wp_unslash($_POST['cb_new_practice']))
        );
    }

    // Meditation methods
    if (!empty($_POST['cb_methods'])) {
        $order->update_meta_data(
            '_cb_methods',
            sanitize_text_field(wp_unslash($_POST['cb_methods']))
        );
    }

    // Meditation familiarity checkboxes
    if (!empty($_POST['cb_familiarity']) && is_array($_POST['cb_familiarity'])) {
        $familiarity = array_map('sanitize_text_field', wp_unslash($_POST['cb_familiarity']));
        $order->update_meta_data('_cb_familiarity', implode(', ', $familiarity));
    }

    // Meditation "Other" text
    if (!empty($_POST['cb_other_text'])) {
        $order->update_meta_data(
            '_cb_other_text',
            sanitize_text_field(wp_unslash($_POST['cb_other_text']))
        );
    }

    // Spiritual companionship experience
    if (!empty($_POST['cb_experience'])) {
        $order->update_meta_data(
            '_cb_experience',
            sanitize_textarea_field(wp_unslash($_POST['cb_experience']))
        );
    }

    // QHHT questions
    if (!empty($_POST['cb_qhht_questions'])) {
        $order->update_meta_data(
            '_cb_qhht_questions',
            sanitize_textarea_field(wp_unslash($_POST['cb_qhht_questions']))
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
}

/**
 * Ensure a customer account exists and attach the order.
 * If new, send activation email.
 *
 * @param int $order_id WooCommerce order ID.
 * @return int|WP_Error User ID or error.
 */
public static function attach_order_to_account($order_id) {
    if (is_user_logged_in()) {
        return get_current_user_id();
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return new WP_Error('invalid_order', 'Order not found.');
    }

    $name  = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $email = $order->get_billing_email();

    $user = get_user_by('email', $email);

    if (!$user) {
        // Split name into first/last
        $parts = explode(' ', $name, 2);
        $first = $parts[0] ?? '';
        $last  = $parts[1] ?? '';

        // Generate a unique username from email
        $username = sanitize_user(current(explode('@', $email)), true);
        if (username_exists($username)) {
            $username .= '_' . wp_generate_password(4, false);
        }

        // Create user with a random password
        $password = wp_generate_password();
        $user_id  = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        // Update profile fields
        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => $name,
        ]);

        $user = get_user_by('id', $user_id);

        // Send activation email
        wp_new_user_notification($user_id, null, 'user');
    }

    // Attach order to user
    $order->set_customer_id($user->ID);
    $order->save();

    return $user->ID;
}

public static function create_calendly_invitee() {
    $order_id = absint(get_query_var('order-received'));
    $api_key  = (string) get_option(CB_Constants::OPT_API_TOKEN, '');
    $order    = wc_get_order($order_id);

    if (!$order) {
        echo "Order not found: $order_id";
        return;
    }

    $token = $order->get_meta('_cb_security_token');
    if (!$token) {
        echo "Security token not found for order: $order_id";
        return;
    }

    $event_type = "https://api.calendly.com/event_types/" . self::get_event_uuid_from_order($order_id);

    $payload = [
        'event_type' => $event_type,
        'start_time' => $order->get_meta('_cb_meeting_time'),
        'invitee' => [
            'email'      => $order->get_billing_email(),
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'name'       => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'timezone'   => wp_timezone_string(),
        ],
        'location' => [
            'kind'     => $order->get_meta('_cb_meeting_location') === '1' ? 'zoom' : 'physical',
            'location' => $order->get_meta('_cb_meeting_location') === '1'
                ? 'Zoom'
                : "HIER Life - Skeete's Road Jackmans, St. Michael",
        ],
    ];

    $questions = [];
    $slug = get_post_field('post_name', $order->get_product_id());

    switch ($slug) {
        case 'initial-consultation':
            $questions[] = [
                'question' => 'Order ID (see payment)',
                'answer'   => (string) $order_id,
                'position' => 0,
            ];
            $questions[] = [
                'question' => 'Please let me know how you became aware of HIER Life.',
                'answer'   => (string) $order->get_meta('_cb_hier_intro'),
                'position' => 1,
            ];
            break;

        case 'meditation-session':
            $questions[] = [
                'question' => 'Order ID',
                'answer'   => (string) $order_id,
                'position' => 0,
            ];
            $questions[] = [
                'question' => 'Please share anything that will help prepare for our meeting.',
                'answer'   => (string) $order->get_meta('_cb_prep_notes'),
                'position' => 1,
            ];
            $questions[] = [
                'question' => 'Are you new to formal practice of meditation?',
                'answer'   => (string) $order->get_meta('_cb_new_practice'),
                'position' => 2,
            ];
            $questions[] = [
                'question' => 'If you have practiced before, what methods have you explored? If none respond - N/A',
                'answer'   => (string) $order->get_meta('_cb_methods'),
                'position' => 3,
            ];
            $questions[] = [
                'question' => 'Do you have any familiarity with the following? Whether you have employed them or not',
                'answer'   => (string) $order->get_meta('_cb_familiarity'),
                'position' => 4,
            ];
            if ($order->get_meta('_cb_other_text')) {
                $questions[] = [
                    'question' => 'Other practice specified',
                    'answer'   => (string) $order->get_meta('_cb_other_text'),
                    'position' => 5,
                ];
            }
            break;

        case 'spiritual-companionship':
            $questions[] = [
                'question' => 'Order ID',
                'answer'   => (string) $order_id,
                'position' => 0,
            ];
            $questions[] = [
                'question' => 'Since your previous session is there any experience or thought that you would want to raise in the coming session?',
                'answer'   => (string) $order->get_meta('_cb_experience'),
                'position' => 1,
            ];
            break;

        case 'reconnective-healing':
            $questions[] = [
                'question' => 'Order ID',
                'answer'   => (string) $order_id,
                'position' => 0,
            ];
            $questions[] = [
                'question' => 'Please share anything that will help prepare for our meeting.',
                'answer'   => (string) $order->get_meta('_cb_prep_notes'),
                'position' => 1,
            ];
            break;

        case 'qhht-session':
            $questions[] = [
                'question' => 'Please share anything that will help prepare for our meeting.',
                'answer'   => (string) $order->get_meta('_cb_prep_notes'),
                'position' => 0,
            ];
            $questions[] = [
                'question' => 'Write 6 questions you would want answers for during the session.',
                'answer'   => (string) $order->get_meta('_cb_qhht_questions'),
                'position' => 1,
            ];
            break;
    }

    $payload['questions_and_answers'] = $questions;

    $response = wp_remote_post('https://api.calendly.com/invitees', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        $order->add_order_note('Calendly API error: ' . $response->get_error_message());
    } elseif (wp_remote_retrieve_response_code($response) !== 201) {
        $order->add_order_note('Calendly API failed. Response: ' . wp_remote_retrieve_body($response));
    }

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 201) {
        $order->delete_meta_data('_cb_security_token');
        $order->save();
    }
}

/**
 * Get the event UUID from product meta via order ID.
 *
 * @param int $order_id WooCommerce order ID
 * @return string|null Event UUID or null if not found
 */
public static function get_event_uuid_from_order($order_id) {
    $order = wc_get_order($order_id);

    if (!$order) {
        return null;
    }

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();

        if ($product instanceof WC_Product) {
            $event_uuid = $product->get_meta('_cb_event_uuid');

            if (!empty($event_uuid)) {
                return (string) $event_uuid; // Return the first UUID found
            }
        }
    }

    return null; // No UUID found
}

public static function add_to_emails($order, $sent_to_admin, $plain_text, $email) {
    $date     = (string) $order->get_meta('_cb_meeting_date');
    $time     = CB_Timezone_Converter::to_site_time($order->get_meta('_cb_meeting_time'), 'H:i A');
    $location = (string) $order->get_meta('_cb_meeting_location');
    $intro    = (string) $order->get_meta('_cb_hier_intro');
    $notes    = (string) $order->get_meta('_cb_meeting_notes');

    // Map location codes to labels
    $location_label = '';
    if ($location === '1') {
        $location_label = __('Zoom - Web conferencing details provided upon confirmation.', 'calendly-bookings');
    } elseif ($location === '2') {
        $location_label = __("HIER Life - Skeete's Road Jackmans, St. Michael", 'calendly-bookings');
    }

    // Collect scheduling URLs from products in the order
    $meeting_links = [];
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        if (!$product_id) continue;

        $base_url = get_post_meta($product_id, '_cb_scheduling_url', true);
        if (!$base_url) continue;

        // Build scheduling URL with date/time
        $scheduling_url = trailingslashit($base_url) . $date . 'T' . $time . 'Z';

        // Shared params for Calendly prefill
        $params = [
            'name'     => $order->get_formatted_billing_full_name(),
            'email'    => $order->get_billing_email(),
            'location' => $location_label,
            'a1'       => $order->get_order_number(),
            'a2'       => $intro,
        ];

        // Build confirmation URL (WordPress handles encoding)
        $confirmation_url = add_query_arg($params, $scheduling_url);

        $meeting_links[] = [
            'product_name'     => $item->get_name(),
            'confirmation_url' => $confirmation_url,
        ];
    }

    if (empty($meeting_links)) return;

    if ($plain_text) {
        echo "\n" . __('Meeting Details', 'calendly-bookings') . "\n";
        echo "--------------------------\n";
        if ($date) echo __('Date:', 'calendly-bookings') . ' ' . sanitize_text_field($date) . "\n";
        if ($time) echo __('Time:', 'calendly-bookings') . ' ' . sanitize_text_field($time) . "\n";
        if ($location_label) echo __('Location:', 'calendly-bookings') . ' ' . sanitize_text_field($location_label) . "\n";
        if ($intro) echo __('Intro:', 'calendly-bookings') . ' ' . sanitize_text_field($intro) . "\n";
        if ($notes && $notes !== 'Nil') echo __('Notes:', 'calendly-bookings') . ' ' . sanitize_textarea_field($notes) . "\n";

        foreach ($meeting_links as $link) {
            echo __('Product:', 'calendly-bookings') . ' ' . $link['product_name'] . "\n";
            echo __('Confirmation URL:', 'calendly-bookings') . ' ' . esc_url($link['confirmation_url']) . "\n";
        }
    } else {
        echo '<h3>' . esc_html__('Meeting Details', 'calendly-bookings') . '</h3><ul>';
        if ($date) printf('<li><strong>%s</strong> %s</li>', esc_html__('Date:', 'calendly-bookings'), esc_html($date));
        if ($time) printf('<li><strong>%s</strong> %s</li>', esc_html__('Time:', 'calendly-bookings'), esc_html($time));
        if ($location_label) printf('<li><strong>%s</strong> %s</li>', esc_html__('Location:', 'calendly-bookings'), esc_html($location_label));
        if ($intro) printf('<li><strong>%s</strong> %s</li>', esc_html__('Intro:', 'calendly-bookings'), esc_html($intro));
        if ($notes && $notes !== 'Nil') {
            printf('<li><strong>%s</strong> %s</li>', esc_html__('Notes:', 'calendly-bookings'), nl2br(esc_html($notes)));
        }

        foreach ($meeting_links as $link) {
            printf('<li><strong>%s</strong> %s<br><strong>%s</strong> <a href="%s" target="_blank">%s</a></li>',
                esc_html__('Product:', 'calendly-bookings'),
                esc_html($link['product_name']),
                esc_html__('Confirmation URL:', 'calendly-bookings'),
                esc_url($link['confirmation_url']),
                esc_html($link['confirmation_url'])
            );
        }
        echo '</ul>';
    }
}

public static function add_to_my_account($order) {
    $date     = (string) $order->get_meta('_cb_meeting_date');
    $time     = (string) $order->get_meta('_cb_meeting_time');
    $location = (string) $order->get_meta('_cb_meeting_location');
    $intro    = (string) $order->get_meta('_cb_hier_intro');
    $notes    = (string) $order->get_meta('_cb_meeting_notes');

    // Map location codes to labels
    $location_label = '';
    if ($location === '1') {
        $location_label = __('Zoom - Web conferencing details provided upon confirmation.', 'calendly-bookings');
    } elseif ($location === '2') {
        $location_label = __("HIER Life - Skeete's Road Jackmans, St. Michael", 'calendly-bookings');
    }

    // Skip if nothing to show
    if (!$date && !$time && !$location_label && !$intro && !$notes) {
        return;
    }

    echo '<section class="woocommerce-order-details">';
    echo '<h2>' . esc_html__('Meeting Details', 'calendly-bookings') . '</h2>';
    echo '<table class="woocommerce-table shop_table meeting_details"><tbody>';

    if ($date) {
        echo '<tr><th>' . esc_html__('Date', 'calendly-bookings') . '</th><td>' . esc_html($date) . '</td></tr>';
    }
    if ($time) {
        // Optionally format with CB_Timezone_Converter
        $formatted_time = CB_Timezone_Converter::to_site_time($time, 'H:i A');
        echo '<tr><th>' . esc_html__('Time', 'calendly-bookings') . '</th><td>' . esc_html($formatted_time) . '</td></tr>';
    }
    if ($location_label) {
        echo '<tr><th>' . esc_html__('Location', 'calendly-bookings') . '</th><td>' . esc_html($location_label) . '</td></tr>';
    }
    if ($intro) {
        echo '<tr><th>' . esc_html__('Initial introduction:', 'calendly-bookings') . '</th><td>' . esc_html($intro) . '</td></tr>';
    }
    if ($notes && $notes !== 'Nil') {
        echo '<tr><th>' . esc_html__('Notes', 'calendly-bookings') . '</th><td>' . nl2br(esc_html($notes)) . '</td></tr>';
    }

    echo '</tbody></table></section>';
}

public static function add_admin_column($columns) {
    $columns['cb_meeting'] = __('Meeting', 'calendly-bookings');
    return $columns;
}

public static function render_admin_column($column, $post_id) {
    if ($column === 'cb_meeting') {
        $order    = wc_get_order($post_id);
        if (!$order) {
            echo '—';
            return;
        }

        $date     = (string) $order->get_meta('_cb_meeting_date');
        $time_raw = (string) $order->get_meta('_cb_meeting_time');
        $location = (string) $order->get_meta('_cb_meeting_location');

        // Format time if available
        $time = $time_raw ? CB_Timezone_Converter::to_site_time($time_raw, 'H:i A') : '';

        // Map location codes to labels
        $location_label = '';
        if ($location === '1') {
            $location_label = __('Zoom', 'calendly-bookings');
        } elseif ($location === '2') {
            $location_label = __("HIER Life", 'calendly-bookings');
        }

        // Build summary
        $summary_parts = array_filter([$date, $time, $location_label]);
        echo !empty($summary_parts) ? esc_html(implode(' ', $summary_parts)) : '—';
    }
}
    /*
    public static function maybe_override_thankyou() {
        if (!is_order_received_page()) return;

        $order_id = absint(get_query_var('order-received'));
        $order    = wc_get_order($order_id);
		$status = $order->get_status();
		if (!$order) return;
		
		$total = (float) $order->get_total();
		if($total > 0){
			$approval_code = (string) $order->get_meta('approval_code');
			if (stripos($approval_code, 'DECLINED') !== false) {
				
				return;
			}
		}
		
        if (self::order_has_meeting($order)) {
			remove_all_actions('woocommerce_thankyou');
			remove_action('woocommerce_thankyou', 'woocommerce_thankyou_order_received_text', 10);
			remove_all_actions('woocommerce_order_details_after_order_table');
			
			add_action('woocommerce_thankyou', [__CLASS__, 'render_meeting_thankyou'], 10, 1);
			#add_action('woocommerce_thankyou', [__CLASS__, 'create_calendly_invitee'], 10, 1);

			
        }
    }
*/

public static function maybe_override_thankyou(): void {
    // Only run on the order received page
    if (!is_order_received_page()) {
        return;
    }

    // Get order id from query var
    $order_id = absint(get_query_var('order-received'));
    if (!$order_id) {
        return;
    }

    // Load order and validate
    $order = wc_get_order($order_id);
    if (!$order instanceof \WC_Order) {
        return;
    }

    // If order total > 0, check approval code for declines
    $total = (float) $order->get_total();
    if ($total > 0) {
        $approval_code = (string) $order->get_meta('approval_code', true);
        if ($approval_code !== '' && stripos($approval_code, 'DECLINED') !== false) {
            // Payment declined — do not override
            $order->add_order_note(__('Calendly invite not created: payment declined.', 'calendly-bookings'));
            return;
        }
    }

    // If order does not contain a meeting product, do nothing
    if (!self::order_has_meeting($order)) {
        return;
    }

    // Remove default thankyou actions so our custom renderer is the first thing shown.
    remove_all_actions('woocommerce_thankyou');
    remove_all_actions('woocommerce_order_details_after_order_table');
    remove_all_actions('woocommerce_order_details_before_order_table');

    // Add our custom renderer first
    add_action('woocommerce_thankyou', [__CLASS__, 'render_meeting_thankyou'], 1, 1);

    // Run post-payment processes (Calendly invite, etc.) after rendering
    add_action('woocommerce_thankyou', [__CLASS__, 'run_after_payment_processes'], 20, 1);
}

public static function run_after_payment_processes($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    // Create Calendly invitee
    self::create_calendly_invitee();

    // Attach order to account if needed
    self::attach_order_to_account($order_id);

    // Add any other post-payment tasks here (logging, notifications, etc.)
}

public static function render_meeting_thankyou($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $date     = (string) $order->get_meta('_cb_meeting_date');
    $time     = (string) $order->get_meta('_cb_meeting_time');
    $location = (string) $order->get_meta('_cb_meeting_location');
    $intro    = (string) $order->get_meta('_cb_hier_intro');
    $notes    = (string) ($order->get_customer_note() ?: 'Nil');

    // Collect scheduling URLs from products in the order
    $meeting_links = [];
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        if (!$product_id) continue;

        $base_url = get_post_meta($product_id, '_cb_scheduling_url', true);
        if (!$base_url) continue;

        $url = trailingslashit($base_url) . $date . 'T' . $time . 'Z';
        $meeting_links[] = [
            'product_name'   => $item->get_name(),
            'scheduling_url' => $url,
            'slug'           => get_post_field('post_name', $product_id),
        ];
    }

    if (empty($meeting_links)) return;

    $first_link     = $meeting_links[0];
    $scheduling_url = $time ? trailingslashit(rawurldecode($first_link['scheduling_url'])) : $first_link['scheduling_url'];
    $slug           = $first_link['slug'];

    // Shared params
    $params = [
        'name'     => $order->get_formatted_billing_full_name(),
        'email'    => $order->get_billing_email(),
        'location' => $location,
        'a1'       => $order->get_order_number(),
    ];

    // Add dynamic fields based on product slug
    switch ($slug) {
        case 'initial-consultation':
            $params['a2'] = $intro;
            break;

        case 'meditation-session':
            $params['prep_notes']   = $order->get_meta('_cb_prep_notes');
            $params['new_practice'] = $order->get_meta('_cb_new_practice');
            $params['methods']      = $order->get_meta('_cb_methods');
            $params['familiarity']  = $order->get_meta('_cb_familiarity');
            $params['other_text']   = $order->get_meta('_cb_other_text');
            break;

        case 'spiritual-companionship':
            $params['experience'] = $order->get_meta('_cb_experience');
            break;

        case 'reconnective-healing':
            $params['prep_notes'] = $order->get_meta('_cb_prep_notes');
            break;

        case 'qhht-session':
            $params['prep_notes']     = $order->get_meta('_cb_prep_notes');
            $params['qhht_questions'] = $order->get_meta('_cb_qhht_questions');
            break;
    }

    // Build confirmation URL
    $confirmation_url = add_query_arg(array_map('urlencode', $params), $scheduling_url);
    ?>
    <!-- Calendly embed -->
    <div id="calendly-wrapper" style="margin-top:2rem;">
        <p>
            <?php echo esc_html__('Please confirm the details below before scheduling this event. If you do not see the session details, click on the confirmation link provided.', 'calendly-bookings'); ?>
            <br>
            <a href="<?php echo esc_url($confirmation_url); ?>" target="_blank">
                <?php echo esc_html($confirmation_url); ?>
            </a>
        </p>
        <div id="calendly-embed" style="min-width:320px;height:700px;"></div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var wrapper = document.getElementById("calendly-wrapper");
        var embed   = document.getElementById("calendly-embed");
        var params  = <?php echo wp_json_encode($params); ?>;
        var schedulingUrl = "<?php echo esc_js($scheduling_url); ?>";

        var url = schedulingUrl + "?hide_event_type_details=1&hide_gdpr_banner=1";
        Object.keys(params).forEach(function(key) {
            url += "&" + encodeURIComponent(key) + "=" + encodeURIComponent(params[key]);
        });

        wrapper.style.display = "block";
        wrapper.scrollIntoView({ behavior: "smooth" });

        Calendly.initInlineWidget({
            url: url,
            text: 'Confirm Booking',
            parentElement: embed,
            prefill: params,
            utm: {},
            resize: true,
        });
    });
    </script>
    <?php
}
	
public static function enqueue_calendly_embed() {
    wp_enqueue_script(
        'calendly-widget',
        'https://assets.calendly.com/assets/external/widget.js',
        ['jquery'], // ensure jQuery is loaded first
        null,
        true // load in footer
    );
}

public static function order_has_meeting($order): bool {
    if (!$order instanceof \WC_Order) {
        error_log('[CB_Checkout] Invalid or missing order object passed to order_has_meeting().');
        return false;
    }

    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        if (!$product) {
            error_log(sprintf('[CB_Checkout] Item %d has no product.', $item_id));
            continue;
        }

        $product_id = $product->get_id();
        $parent_id  = $product->is_type('variation') ? $product->get_parent_id() : $product_id;

        $uuid        = (string) get_post_meta($product_id, '_cb_event_uuid', true);
        $parent_uuid = $parent_id ? (string) get_post_meta($parent_id, '_cb_event_uuid', true) : '';

        // Check product categories
        $categories = wc_get_product_category_list($product_id);
        $is_meeting_category = has_term(['meeting', 'session'], 'product_cat', $product_id);

        error_log(sprintf(
            '[CB_Checkout] Checking product %d (parent %d) — UUID: %s | Parent UUID: %s | Categories: %s',
            $product_id,
            $parent_id,
            $uuid ?: 'none',
            $parent_uuid ?: 'none',
            $categories
        ));

        if ($uuid !== '' || $parent_uuid !== '' || $is_meeting_category) {
            error_log('[CB_Checkout] Meeting product detected for order ' . $order->get_id());
            return true;
        }
    }

    error_log('[CB_Checkout] No meeting products found for order ' . $order->get_id());
    return false;
}

}
