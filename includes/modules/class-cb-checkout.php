<?php
//includes/modules/class-cb-checkout.php
namespace Calendly_Bookings\Modules;

use WC_Order;
use Calendly_Bookings\CB_Constants;
use Calendly_Bookings\Utils\CB_Timezone_Converter;

class CB_Checkout {

public static function register(): void {
    // Capture custom fields when Add to Cart is clicked
    add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'capture_form_data'], 10, 3);

    // Prefill checkout fields from cart/session
    add_filter('woocommerce_checkout_get_value', [__CLASS__, 'prefill_checkout'], 10, 2);

    // Render hidden inputs on checkout page
    add_action('woocommerce_after_order_notes', [__CLASS__, 'add_checkout_fields']);

    // Save custom fields into order meta
    add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_order_meta'], 10, 2);
    // Persist the complete booking snapshot on the order line item. This is the
    // authoritative hand-off from the product/cart form to checkout and does not
    // depend on checkout-page hidden inputs being serialized by the browser.
    add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_booking_item_meta'], 10, 4);

    // Attach order to account after payment
    add_action('woocommerce_payment_complete', [__CLASS__, 'attach_order_to_account']);

    // Display custom fields in emails, My Account, and admin
    add_action('woocommerce_email_order_meta', [__CLASS__, 'add_to_emails'], 10, 4);
    add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'add_to_my_account']);
    add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'add_to_my_account']);
    add_filter('manage_edit-shop_order_columns', [__CLASS__, 'add_admin_column']);
    add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'render_admin_column'], 10, 2);

    // Show custom fields in cart and checkout review
    add_filter('woocommerce_get_item_data', [__CLASS__, 'display_cart_item_data'], 10, 2);

    // Override Thank You page
    add_action('template_redirect', [__CLASS__, 'maybe_override_thankyou']);

}

public static function add_checkout_fields($checkout) {
    $booking = self::get_cart_booking_snapshot();

    $fields = [
        'cb_timezone',
        'cb_meeting_location',
        'cb_meeting_date',
        'cb_meeting_time',
        'cb_meeting_start_iso',
        'cb_event_uuid',
        'cb_event_type_uri',
        'cb_meeting_location_details',
        'cb_meeting_location_detail_text',
        'cb_hier_intro',
        'cb_prep_notes',
        'cb_new_practice',
        'cb_methods',
        'cb_other_text',
        'cb_experience',
        'cb_qhht_questions',
        'order_comments',
    ];

    foreach ($fields as $field) {
        $value = array_key_exists($field, $booking) ? $booking[$field] : $checkout->get_value($field);
        if (is_array($value)) {
            $value = implode(', ', array_map('sanitize_text_field', $value));
        }
        echo '<input type="hidden" name="' . esc_attr($field) . '" value="' . esc_attr((string) $value) . '" />';
    }

    $answers = isset($booking['cb_calendly_answers']) && is_array($booking['cb_calendly_answers'])
        ? $booking['cb_calendly_answers']
        : [];
    echo '<input type="hidden" name="cb_calendly_answers_json" value="' . esc_attr(wp_json_encode($answers)) . '" />';

    $familiarity = $booking['cb_familiarity'] ?? $checkout->get_value('cb_familiarity');
    if (is_array($familiarity)) {
        $familiarity = implode(',', array_map('sanitize_text_field', $familiarity));
    }
    if ($familiarity !== '' && $familiarity !== null) {
        echo '<input type="hidden" name="cb_familiarity" value="' . esc_attr((string) $familiarity) . '" />';
    }
}

/**
 * Return the first meeting-booking cart item as a normalized snapshot.
 * The cart item is the authoritative source because it is created directly
 * from the product-page booking form and is persisted by WooCommerce in the
 * customer session through checkout.
 */
private static function get_cart_booking_snapshot(): array {
    if (!function_exists('WC') || !WC()->cart) {
        return [];
    }

    foreach (WC()->cart->get_cart() as $cart_item) {
        if (empty($cart_item['cb_event_uuid']) && empty($cart_item['cb_meeting_start_iso'])) {
            continue;
        }

        $snapshot = $cart_item;
        $snapshot['cb_calendly_answers'] = self::sanitize_answers($cart_item['cb_calendly_answers'] ?? []);
        if (isset($snapshot['cb_familiarity']) && is_array($snapshot['cb_familiarity'])) {
            $snapshot['cb_familiarity'] = array_values(array_filter(array_map('sanitize_text_field', $snapshot['cb_familiarity']), 'strlen'));
        }
        return $snapshot;
    }

    return [];
}

private static function sanitize_answers($answers): array {
    if (!is_array($answers)) {
        return [];
    }

    $clean = [];
    foreach ($answers as $key => $value) {
        $key = sanitize_key((string) $key);
        if ($key === '') {
            continue;
        }
        if (is_array($value)) {
            $clean[$key] = array_values(array_filter(array_map('sanitize_text_field', $value), 'strlen'));
        } else {
            $clean[$key] = sanitize_textarea_field((string) $value);
        }
    }
    return $clean;
}

public static function capture_form_data($cart_item_data, $product_id, $variation_id) {
    $fields = [
        'cb_timezone' => [CB_Customer_Time::class, 'valid'],
        'cb_meeting_location' => 'sanitize_text_field',
        'cb_meeting_date'     => 'sanitize_text_field',
        'cb_meeting_time'     => 'sanitize_text_field',
        'cb_meeting_start_iso'=> 'sanitize_text_field',
        'cb_event_uuid'       => 'sanitize_text_field',
        'cb_event_type_uri'   => 'esc_url_raw',
        'cb_meeting_location_details' => 'sanitize_text_field',
        'cb_meeting_location_detail_text' => 'sanitize_text_field',
        'billing_first_name'  => 'sanitize_text_field',
        'billing_last_name'   => 'sanitize_text_field',
        'billing_email'       => 'sanitize_email',
        'cb_hier_intro'       => 'sanitize_textarea_field',
        'cb_prep_notes'       => 'sanitize_textarea_field',
        'cb_new_practice'     => 'sanitize_text_field',
        'cb_methods'          => 'sanitize_text_field',
        'cb_other_text'       => 'sanitize_text_field',
        'cb_experience'       => 'sanitize_textarea_field',
        'cb_qhht_questions'   => 'sanitize_textarea_field',
        'order_comments'      => 'sanitize_textarea_field',
    ];

    foreach ($fields as $key => $callback) {
        if (!empty($_POST[$key])) {
            $cart_item_data[$key] = call_user_func($callback, wp_unslash($_POST[$key]));
        }
    }

    if (!empty($_POST['cb_familiarity'])) {
        $cart_item_data['cb_familiarity'] = array_map('sanitize_text_field', (array) wp_unslash($_POST['cb_familiarity']));
    }

    $answers = [];
    if (isset($_POST['cb_calendly_answers']) && is_array($_POST['cb_calendly_answers'])) {
        foreach (wp_unslash($_POST['cb_calendly_answers']) as $key => $value) {
            $key = sanitize_key($key);
            if (is_array($value)) {
                $answers[$key] = array_values(array_filter(array_map('sanitize_text_field', $value), 'strlen'));
            } else {
                $answers[$key] = sanitize_textarea_field($value);
            }
        }
    }
    if (isset($_POST['cb_calendly_answers_other']) && is_array($_POST['cb_calendly_answers_other'])) {
        foreach (wp_unslash($_POST['cb_calendly_answers_other']) as $key => $value) {
            $key = sanitize_key($key);
            $value = sanitize_text_field($value);
            if ($key !== '' && $value !== '') {
                $answers[$key . '_other'] = $value;
            }
        }
    }
    if ($answers) {
        $cart_item_data['cb_calendly_answers'] = $answers;
    }

    // Capture server-owned labels alongside keyed answers for readable order history.
    $definition = CB_Frontend::get_event_type_definition((int) $product_id);
    $labels = [];
    foreach ((array) ($definition['custom_questions'] ?? []) as $question) {
        if (!is_array($question) || empty($question['enabled'])) continue;
        $labels[CB_Frontend::question_key($question)] = sanitize_text_field((string) ($question['name'] ?? ''));
    }
    $cart_item_data['cb_question_labels'] = $labels;
    foreach ((array) ($definition['locations'] ?? []) as $index => $location) {
        if (!is_array($location)) continue;
        if (($cart_item_data['cb_meeting_location'] ?? '') === CB_Frontend::location_key($location, (int) $index)) {
            $cart_item_data['cb_meeting_location_label'] = self::location_label($location);
            break;
        }
    }

    // Make identical submissions unique in the cart while preserving the captured booking data.
    if (!empty($cart_item_data['cb_meeting_start_iso'])) {
        $cart_item_data['cb_booking_key'] = hash('sha256', $product_id . '|' . $cart_item_data['cb_meeting_start_iso'] . '|' . ($cart_item_data['billing_email'] ?? ''));
    }

    return $cart_item_data;
}

public static function display_cart_item_data($item_data, $cart_item) {
    $start = (string) ($cart_item['cb_meeting_start_iso'] ?? $cart_item['cb_meeting_time'] ?? '');
    if ($start) {
        $zone = CB_Customer_Time::valid((string) ($cart_item['cb_timezone'] ?? ''));
        $zone = $zone ? new \DateTimeZone($zone) : CB_Customer_Time::viewer_timezone();
        $formatted = CB_Customer_Time::format($start, $zone) . ' (' . $zone->getName() . ')';
        $item_data[] = ['key' => __('Meeting date and time', 'calendly-bookings'), 'value' => $formatted, 'display' => '<time data-cb-time="' . esc_attr($start) . '">' . esc_html($formatted) . '</time>'];
    }
    $keys = [
        'cb_meeting_location_label' => __('Location', 'calendly-bookings'),
        'cb_hier_intro'       => __('Intro', 'calendly-bookings'),
        'cb_prep_notes'       => __('Preparation Notes', 'calendly-bookings'),
        'cb_new_practice'     => __('New Practice', 'calendly-bookings'),
        'cb_methods'          => __('Methods', 'calendly-bookings'),
        'cb_other_text'       => __('Other Practice', 'calendly-bookings'),
        'cb_experience'       => __('Experience', 'calendly-bookings'),
        'cb_qhht_questions'   => __('QHHT Questions', 'calendly-bookings'),
        'order_comments'      => __('Notes', 'calendly-bookings'),
    ];

    foreach ($keys as $key => $label) {
        if (!empty($cart_item[$key])) {
            $item_data[] = [
                'key'   => $label,
                'value' => wc_clean($cart_item[$key]),
            ];
        }
    }

    if (!empty($cart_item['cb_familiarity'])) {
        $item_data[] = [
            'key'   => __('Familiarity', 'calendly-bookings'),
            'value' => is_array($cart_item['cb_familiarity'])
                ? implode(', ', array_map('wc_clean', $cart_item['cb_familiarity']))
                : wc_clean($cart_item['cb_familiarity']),
        ];
    }

    foreach ((array) ($cart_item['cb_calendly_answers'] ?? []) as $key => $value) {
        if (str_ends_with($key, '_other')) continue;
        $value = is_array($value) ? implode("\n", $value) : (string) $value;
        if (!empty($cart_item['cb_calendly_answers'][$key . '_other'])) $value .= "\nOther: " . $cart_item['cb_calendly_answers'][$key . '_other'];
        if ($value !== '') $item_data[] = ['key' => $cart_item['cb_question_labels'][$key] ?? __('Additional response', 'calendly-bookings'), 'value' => $value, 'display' => nl2br(esc_html($value))];
    }
    return $item_data;
}

public static function prefill_checkout($value, $input) {
    $cart = WC()->cart;
    if ($cart) {
        foreach ($cart->get_cart() as $item) {
            if (isset($item[$input])) {
                return $item[$input];
            }
        }
    }
    if (!empty($_POST[$input])) {
        return is_array($_POST[$input]) ? array_map('sanitize_text_field', wp_unslash($_POST[$input])) : sanitize_text_field(wp_unslash($_POST[$input]));
    }
    return $value;
}

public static function save_order_meta(\WC_Order $order, $data) {
    // Prefer the persisted cart/line-item booking snapshot. Checkout POST values
    // remain a compatibility fallback, but they are never the only source.
    $booking = [];
    foreach ($order->get_items('line_item') as $item) {
        $event_uuid = (string) $item->get_meta('_cb_booking_event_uuid', true);
        $start_iso  = (string) $item->get_meta('_cb_booking_start_iso', true);
        if ($event_uuid || $start_iso) {
            $booking = [
                'cb_timezone' => (string) $item->get_meta('_cb_booking_timezone', true),
                'cb_meeting_location' => (string) $item->get_meta('_cb_booking_location', true),
                'cb_meeting_date' => (string) $item->get_meta('_cb_booking_date', true),
                'cb_meeting_time' => (string) $item->get_meta('_cb_booking_time', true),
                'cb_meeting_start_iso' => $start_iso,
                'cb_event_uuid' => $event_uuid,
                'cb_event_type_uri' => (string) $item->get_meta('_cb_booking_event_type_uri', true),
                'cb_meeting_location_details' => (string) $item->get_meta('_cb_booking_location_details', true),
                'cb_meeting_location_detail_text' => (string) $item->get_meta('_cb_booking_location_detail_text', true),
                'cb_hier_intro' => (string) $item->get_meta('_cb_booking_hier_intro', true),
                'cb_prep_notes' => (string) $item->get_meta('_cb_booking_prep_notes', true),
                'cb_new_practice' => (string) $item->get_meta('_cb_booking_new_practice', true),
                'cb_methods' => (string) $item->get_meta('_cb_booking_methods', true),
                'cb_other_text' => (string) $item->get_meta('_cb_booking_other_text', true),
                'cb_experience' => (string) $item->get_meta('_cb_booking_experience', true),
                'cb_qhht_questions' => (string) $item->get_meta('_cb_booking_qhht_questions', true),
                'cb_familiarity' => (string) $item->get_meta('_cb_booking_familiarity', true),
                'cb_calendly_answers' => self::sanitize_answers(json_decode((string) $item->get_meta('_cb_booking_answers', true), true)),
                'cb_question_labels' => json_decode((string) $item->get_meta('_cb_booking_question_labels', true), true) ?: [],
                'cb_meeting_location_label' => (string) $item->get_meta('_cb_booking_location_label', true),
                'order_comments' => (string) $item->get_meta('_cb_booking_order_comments', true),
            ];
            break;
        }
    }

    $fields = [
        'cb_timezone' => ['_cb_timezone', [CB_Customer_Time::class, 'valid']],
        'cb_meeting_location' => ['_cb_meeting_location', 'sanitize_text_field'],
        'cb_meeting_date'     => ['_cb_meeting_date', 'sanitize_text_field'],
        'cb_meeting_time'     => ['_cb_meeting_time', 'sanitize_text_field'],
        'cb_meeting_start_iso'=> ['_cb_meeting_start_iso', 'sanitize_text_field'],
        'cb_event_uuid'       => ['_cb_event_uuid', 'sanitize_text_field'],
        'cb_event_type_uri'   => ['_cb_event_type_uri', 'esc_url_raw'],
        'cb_meeting_location_details' => ['_cb_meeting_location_details', 'sanitize_text_field'],
        'cb_meeting_location_detail_text' => ['_cb_meeting_location_detail_text', 'sanitize_text_field'],
        'cb_hier_intro'       => ['_cb_hier_intro', 'sanitize_textarea_field'],
        'cb_prep_notes'       => ['_cb_prep_notes', 'sanitize_textarea_field'],
        'cb_new_practice'     => ['_cb_new_practice', 'sanitize_text_field'],
        'cb_methods'          => ['_cb_methods', 'sanitize_text_field'],
        'cb_other_text'       => ['_cb_other_text', 'sanitize_text_field'],
        'cb_experience'       => ['_cb_experience', 'sanitize_textarea_field'],
        'cb_qhht_questions'   => ['_cb_qhht_questions', 'sanitize_textarea_field'],
    ];

    foreach ($fields as $post_key => [$meta_key, $callback]) {
        $value = array_key_exists($post_key, $booking) ? $booking[$post_key] : ($_POST[$post_key] ?? '');
        if ($value !== '' && $value !== null) {
            $order->update_meta_data($meta_key, call_user_func($callback, array_key_exists($post_key, $booking) ? $value : wp_unslash($value)));
        }
    }

    $familiarity = array_key_exists('cb_familiarity', $booking) ? $booking['cb_familiarity'] : ($_POST['cb_familiarity'] ?? '');
    if (is_array($familiarity)) {
        $familiarity = implode(', ', array_map('sanitize_text_field', $familiarity));
    } elseif ($familiarity !== '') {
        $familiarity = sanitize_text_field(array_key_exists('cb_familiarity', $booking) ? (string) $familiarity : wp_unslash((string) $familiarity));
    }
    if ($familiarity !== '') {
        $order->update_meta_data('_cb_familiarity', $familiarity);
    }

    $answers = [];
    if (isset($booking['cb_calendly_answers']) && is_array($booking['cb_calendly_answers'])) {
        $answers = self::sanitize_answers($booking['cb_calendly_answers']);
    } elseif (!empty($_POST['cb_calendly_answers_json'])) {
        $decoded = json_decode(wp_unslash($_POST['cb_calendly_answers_json']), true);
        if (is_array($decoded)) {
            $answers = self::sanitize_answers($decoded);
        }
    }
    if ($answers) {
        $order->update_meta_data('_cb_calendly_answers', wp_json_encode($answers));
    }

    $notes = array_key_exists('order_comments', $booking) ? $booking['order_comments'] : ($_POST['order_comments'] ?? '');
    $notes = $notes !== '' ? sanitize_textarea_field(array_key_exists('order_comments', $booking) ? (string) $notes : wp_unslash((string) $notes)) : 'Nil';
    $order->update_meta_data('_cb_meeting_notes', $notes);
    if ($notes !== 'Nil') {
        $order->set_customer_note($notes);
    }

    if ($booking) {
        $snapshot = $booking;
        unset($snapshot['data'], $snapshot['key'], $snapshot['product_id'], $snapshot['variation_id']);
        $snapshot['cb_calendly_answers'] = $answers;
        $order->update_meta_data('_cb_booking_snapshot', wp_json_encode($snapshot));
        $order->update_meta_data('_cb_booking_data_version', '2');
        $order->add_order_note('Calendly booking data persisted from the product booking form.');
    }
}

/**
 * Persist booking fields directly on the WooCommerce order line item.
 * This survives the cart -> checkout transition independently of browser POST
 * serialization and is later promoted to order meta by save_order_meta().
 */
public static function save_booking_item_meta($item, $cart_item_key, $values, $order): void {
    if (!is_array($values) || empty($values['cb_event_uuid']) || empty($values['cb_meeting_start_iso'])) {
        return;
    }

    $map = [
        'cb_timezone' => '_cb_booking_timezone',
        'cb_event_uuid' => '_cb_booking_event_uuid',
        'cb_event_type_uri' => '_cb_booking_event_type_uri',
        'cb_meeting_location' => '_cb_booking_location',
        'cb_meeting_location_label' => '_cb_booking_location_label',
        'cb_meeting_date' => '_cb_booking_date',
        'cb_meeting_time' => '_cb_booking_time',
        'cb_meeting_start_iso' => '_cb_booking_start_iso',
        'cb_meeting_location_details' => '_cb_booking_location_details',
        'cb_meeting_location_detail_text' => '_cb_booking_location_detail_text',
        'cb_hier_intro' => '_cb_booking_hier_intro',
        'cb_prep_notes' => '_cb_booking_prep_notes',
        'cb_new_practice' => '_cb_booking_new_practice',
        'cb_methods' => '_cb_booking_methods',
        'cb_other_text' => '_cb_booking_other_text',
        'cb_experience' => '_cb_booking_experience',
        'cb_qhht_questions' => '_cb_booking_qhht_questions',
        'cb_familiarity' => '_cb_booking_familiarity',
        'order_comments' => '_cb_booking_order_comments',
    ];

    foreach ($map as $source => $meta_key) {
        if (!array_key_exists($source, $values) || $values[$source] === '' || $values[$source] === null) {
            continue;
        }
        $value = $values[$source];
        if (is_array($value)) {
            $value = implode(', ', array_map('sanitize_text_field', $value));
        } elseif ($source === 'cb_event_type_uri') {
            $value = esc_url_raw((string) $value);
        } elseif (in_array($source, ['cb_hier_intro','cb_prep_notes','cb_experience','cb_qhht_questions','order_comments'], true)) {
            $value = sanitize_textarea_field((string) $value);
        } else {
            $value = sanitize_text_field((string) $value);
        }
        $item->add_meta_data($meta_key, $value, true);
    }

    if (!empty($values['cb_question_labels']) && is_array($values['cb_question_labels'])) {
        $item->add_meta_data('_cb_booking_question_labels', wp_json_encode(array_map('sanitize_text_field', $values['cb_question_labels'])), true);
    }
    $answers = self::sanitize_answers($values['cb_calendly_answers'] ?? []);
    if ($answers) {
        $item->add_meta_data('_cb_booking_answers', wp_json_encode($answers), true);
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
        return new \WP_Error('invalid_order', 'Order not found.');
    }
    if (!self::order_has_meeting($order)) return (int) $order->get_customer_id();
    if (!is_email($order->get_billing_email())) return new \WP_Error('invalid_email', 'A valid billing email is required.');

    $name  = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $email = $order->get_billing_email();

    $user = get_user_by('email', $email);

    if (!$user) {
        // Split name into first/last
        $parts = explode(' ', $name, 2);
        $first = $parts[0] ?? '';
        $last  = $parts[1] ?? '';

        // Generate a unique username from email
        $username = sanitize_user((string) strstr($email, '@', true), true);
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

public static function get_confirmation_url(
    \WC_Order $order
): string {
    $token = (string) $order->get_meta('_cb_meeting_confirmation_token', true);
    if (!$token) {
        $token = wp_generate_password(32, false, false);
        $order->update_meta_data('_cb_meeting_confirmation_token', $token);
        $order->save();
    }
    return add_query_arg(['token' => $token], home_url('/meeting-scheduled/'));
}

public static function location_label(array $location): string {
    $kind = (string) ($location['kind'] ?? $location['type'] ?? '');
    $label = ucwords(str_replace('_', ' ', $kind));
    $detail = (string) ($location['location'] ?? '');
    return trim($label . ($detail !== '' ? ' — ' . $detail : ''));
}

/** Human-readable values; never expose internal question/location keys. */
public static function meeting_details(\WC_Order $order): array {
    $snapshot = json_decode((string) $order->get_meta('_cb_booking_snapshot', true), true) ?: [];
    if (!$snapshot) {
        foreach ($order->get_items() as $item) {
            $labels = json_decode((string) $item->get_meta('_cb_booking_question_labels', true), true);
            if ($labels) $snapshot['cb_question_labels'] = $labels;
            $location_label = (string) $item->get_meta('_cb_booking_location_label', true);
            if ($location_label) $snapshot['cb_meeting_location_label'] = $location_label;
        }
    }
    $rows = [];
    $start = (string) $order->get_meta('_cb_meeting_start_iso', true);
    if (!$start) $start = (string) ($snapshot['cb_meeting_start_iso'] ?? '');
    if ($start) {
        try {
            $timestamp = (new \DateTimeImmutable($start))->getTimestamp();
            $zone = CB_Customer_Time::order_timezone($order);
            $rows['Meeting date and time'] = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp, $zone) . ' (' . $zone->getName() . ')';
        } catch (\Throwable $e) { /* Preserve other readable fields on legacy orders. */ }
    }
    $location = (string) ($snapshot['cb_meeting_location_label'] ?? '');
    if (!$location) {
        $legacy = (string) $order->get_meta('_cb_meeting_location', true);
        $location = $legacy === '1' ? 'Zoom' : ($legacy === '2' ? 'HIER Life' : '');
    }
    $detail = (string) $order->get_meta('_cb_meeting_location_detail_text', true);
    if ($detail !== '') $location .= ($location ? ' — ' : '') . $detail;
    if ($location !== '') $rows['Location'] = $location;
    $labels = (array) ($snapshot['cb_question_labels'] ?? []);
    if (!$labels) {
        global $wpdb;
        $uuid = (string) $order->get_meta('_cb_event_uuid', true);
        $meta = $uuid ? $wpdb->get_var($wpdb->prepare("SELECT meta FROM {$wpdb->prefix}cb_event_types WHERE uuid=%s LIMIT 1", $uuid)) : '';
        $definition = json_decode((string) $meta, true) ?: [];
        foreach ((array) ($definition['custom_questions'] ?? []) as $question) {
            if (is_array($question)) $labels[CB_Frontend::question_key($question)] = (string) ($question['name'] ?? '');
        }
    }
    $answers = json_decode((string) $order->get_meta('_cb_calendly_answers', true), true);
    if (!is_array($answers)) $answers = (array) ($snapshot['cb_calendly_answers'] ?? []);
    // Older orders can recover readable question names from the stored API response.
    $response = json_decode((string) $order->get_meta('_cb_calendly_create_response', true), true) ?: [];
    $request = json_decode((string) $order->get_meta('_cb_calendly_create_request', true), true) ?: [];
    foreach ((array) ($response['questions_and_answers'] ?? $request['questions_and_answers'] ?? []) as $answer) {
        if (!is_array($answer)) continue;
        $question = ['name' => $answer['question'] ?? '', 'position' => $answer['position'] ?? 0];
        $labels[CB_Frontend::question_key($question)] = (string) $question['name'];
    }
    foreach ($answers as $key => $value) {
        if (str_ends_with($key, '_other')) continue;
        $label = (string) ($labels[$key] ?? 'Additional response');
        $value = is_array($value) ? implode("\n", $value) : (string) $value;
        $other = (string) ($answers[$key . '_other'] ?? '');
        if ($other !== '') $value .= ($value !== '' ? "\n" : '') . 'Other: ' . $other;
        if ($value !== '') {
            $base = $label; $suffix = 2;
            while (array_key_exists($label, $rows)) $label = $base . ' (' . $suffix++ . ')';
            $rows[$label] = $value;
        }
    }
    foreach (['_cb_hier_intro'=>'Introduction','_cb_prep_notes'=>'Preparation notes','_cb_new_practice'=>'New practice','_cb_methods'=>'Methods','_cb_other_text'=>'Other practice','_cb_experience'=>'Experience','_cb_qhht_questions'=>'QHHT questions','_cb_familiarity'=>'Familiarity','_cb_meeting_notes'=>'Notes'] as $key => $label) {
        $value = (string) $order->get_meta($key, true);
        if ($value !== '' && $value !== 'Nil' && !in_array($value, $rows, true)) $rows[$label] = $value;
    }
    return $rows;
}

public static function render_meeting_details(\WC_Order $order, bool $plain_text = false): string {
    $rows = self::meeting_details($order);
    if (!$rows) return '';
    if ($plain_text) {
        $output = "\nMeeting details\n";
        foreach ($rows as $label => $value) $output .= $label . ":\n" . $value . "\n\n";
        return $output;
    }
    $output = '<h3>Meeting details</h3><table class="woocommerce-table shop_table meeting_details"><tbody>';
    foreach ($rows as $label => $value) {
        $display = nl2br(esc_html($value));
        $start = (string) $order->get_meta('_cb_meeting_start_iso', true);
        if ($label === 'Meeting date and time' && $start) $display = '<time data-cb-time="' . esc_attr($start) . '">' . $display . '</time>';
        $output .= '<tr><th style="text-align:left;vertical-align:top">' . esc_html($label) . '</th><td>' . $display . '</td></tr>';
    }
    return $output . '</tbody></table>';
}

public static function add_to_emails($order, $sent_to_admin, $plain_text, $email) {
    if (!self::order_has_meeting($order)) return;
    echo self::render_meeting_details($order, (bool) $plain_text);
    $status = (string) $order->get_meta('_cb_calendly_booking_status', true);
    $page_url = self::get_confirmation_url($order);
    if ($plain_text) {
        echo "Booking status: " . ($status ?: 'pending') . "\nView confirmation: " . esc_url_raw($page_url) . "\n";
    } else {
        echo '<p>Booking status: ' . esc_html($status ?: 'pending') . '</p><p><a href="' . esc_url($page_url) . '">View session confirmation</a></p>';
    }
}

public static function add_to_my_account($order) {
    echo self::render_meeting_details($order);
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

        $details = self::meeting_details($order);
        $summary_parts = array_filter([$details['Meeting date and time'] ?? '', $details['Location'] ?? '']);
        echo $summary_parts ? esc_html(implode(' — ', $summary_parts)) : '—';
    }
}

public static function maybe_override_thankyou(): void {
    if (!is_order_received_page()) return;
    $order_id = absint(get_query_var('order-received'));
    $order = $order_id ? wc_get_order($order_id) : false;
    if (!$order instanceof \WC_Order || !self::order_has_meeting($order)) return;

    remove_all_actions('woocommerce_thankyou');
    remove_all_actions('woocommerce_order_details_after_order_table');
    remove_all_actions('woocommerce_order_details_before_order_table');

    add_action('woocommerce_thankyou', [__CLASS__, 'render_meeting_thankyou'], 1, 1);
    add_action('woocommerce_thankyou', [__CLASS__, 'run_after_payment_processes'], 20, 1);
}

public static function run_after_payment_processes($order_id): void {
    $order = wc_get_order($order_id);
    if (!$order) return;

    CB_Booking_Reconciliation::enqueue((int) $order_id, 0, 'thankyou-retry');
    self::attach_order_to_account((int) $order_id);
}

public static function render_meeting_thankyou($order_id): void {
    $order = wc_get_order($order_id);
    if (!$order) return;
    $status = (string) $order->get_meta('_cb_calendly_booking_status', true);
    $page_url = self::get_confirmation_url($order);
    ?>
    <div class="cb-booking-thankyou" style="margin:2rem 0;">
        <h2><?php esc_html_e('Session Booking', 'calendly-bookings'); ?></h2>
        <p><?php esc_html_e('Your WooCommerce order has been received. Calendly will confirm the appointment and the confirmation page will display only the information received from Calendly.', 'calendly-bookings'); ?></p>
        <p><strong><?php esc_html_e('Booking status:', 'calendly-bookings'); ?></strong> <?php echo esc_html($status ?: 'pending'); ?></p>
        <p><a class="button" href="<?php echo esc_url($page_url); ?>"><?php esc_html_e('View Session Confirmation', 'calendly-bookings'); ?></a></p>
    </div>
    <?php
    echo self::render_meeting_details($order);
}

public static function order_has_meeting($order): bool {
    if (!$order instanceof \WC_Order) {
        CB_Logger::debug('[CB_Checkout] Invalid or missing order object passed to order_has_meeting().');
        return false;
    }

    if ($order->get_meta('_cb_event_uuid', true) || $order->get_meta('_cb_booking_snapshot', true)) return true;
    foreach ($order->get_items() as $item_id => $item) {
        if ($item->get_meta('_cb_booking_event_uuid', true)) return true;
        $product = $item->get_product();
        if (!$product) {
            CB_Logger::debug(sprintf('[CB_Checkout] Item %d has no product.', $item_id));
            continue;
        }

        $product_id = $product->get_id();
        $parent_id  = $product->is_type('variation') ? $product->get_parent_id() : $product_id;

        $uuid        = (string) get_post_meta($product_id, '_cb_event_uuid', true);
        $parent_uuid = $parent_id ? (string) get_post_meta($parent_id, '_cb_event_uuid', true) : '';

        // Check product categories
        $categories = wc_get_product_category_list($product_id);
        $is_meeting_category = has_term(['meeting', 'session'], 'product_cat', $product_id);

        CB_Logger::debug(sprintf(
            '[CB_Checkout] Checking product %d (parent %d) — UUID: %s | Parent UUID: %s | Categories: %s',
            $product_id,
            $parent_id,
            $uuid ?: 'none',
            $parent_uuid ?: 'none',
            $categories
        ));

        if ($uuid !== '' || $parent_uuid !== '' || $is_meeting_category) {
            CB_Logger::debug('[CB_Checkout] Meeting product detected for order ' . $order->get_id());
            return true;
        }
    }

    CB_Logger::debug('[CB_Checkout] No meeting products found for order ' . $order->get_id());
    return false;
}

}
