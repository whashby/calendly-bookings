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
add_action('woocommerce_before_add_to_cart_button', [__CLASS__, 'output_before_cart'], 5);
        add_filter('woocommerce_loop_add_to_cart_link', [__CLASS__, 'replace_loop_add_to_cart'], 10, 3);
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_add_to_cart'], 10, 3);
        add_filter('woocommerce_product_single_add_to_cart_text', [__CLASS__, 'single_add_to_cart_text'], 10);
        add_filter('woocommerce_product_add_to_cart_text', [__CLASS__, 'archive_add_to_cart_text'], 10, 2);
        // Do not render the booking form in the short description: it must be inside the WooCommerce add-to-cart form.
        // add_action('woocommerce_short_description', [__CLASS__, 'output_before_cart']);
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
        if (!is_singular('product') || !self::is_meeting_product()) {
            return;
        }

        wp_enqueue_style(
            'flatpickr-css',
            'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
            [],
            '4.6.13'
        );

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
        $event_type_uri = '';

        if (is_singular('product')) {
            $product_id = get_the_ID();
            $product    = wc_get_product($product_id);

            // Only proceed if product exists and is in the right categories
            if ($product && has_term(['meeting', 'meetings'], 'product_cat', $product_id)) {
                // Retrieve UUID; resolve the supplied HIER Life Calendly URL when legacy products are not yet linked.
                $event_uuid = (string) get_post_meta($product_id, '_cb_event_uuid', true);
                $definition = self::get_event_type_definition($product_id);
                $event_type_uri = (string) ($definition['uri'] ?? '');
                if (!$event_uuid && $event_type_uri) {
                    $event_uuid = basename(parse_url($event_type_uri, PHP_URL_PATH));
                }
                // The Calendly API availability endpoint requires the full Event Type URI.
                // Keep the UUID as the canonical product mapping, but derive the URI from it
                // when the cached Event Type response is unavailable.
                if (!$event_type_uri && $event_uuid) {
                    $event_type_uri = 'https://api.calendly.com/event_types/' . rawurlencode($event_uuid);
                }

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
            'product'        => $product_id,
            'uuid'           => $event_uuid,
            'event_type_uri' => $event_type_uri,
        ];

        // Use a window property rather than a top-level const. The frontend script reads
        // the configuration through window.CB_REST, and this also avoids lexical/global
        // scope differences between browsers and script injection methods.
        wp_add_inline_script(
            'cb-frontend',
            'window.CB_REST = ' . wp_json_encode($data) . '; window.CB_MESSAGES = ' . wp_json_encode($messages) . ';',
            'before'
        );

        wp_localize_script(
            'cb-frontend',
            'cb_ajax_object',
            array( 'ajaxurl' => admin_url('admin-ajax.php')
            )
        );
    }


    
    /**
     * Product -> Calendly URL mappings supplied for HIER Life. These are fallbacks
     * only; persisted product/Event Type mappings remain the primary source.
     */
    public static function known_calendly_url(string $slug): string {
        $map = [
            'hesychia' => 'https://calendly.com/michael-hierlife/hesychia',
            'initial-consultation' => 'https://calendly.com/michael-hierlife/initial-meeting',
            'spiritual-companionship' => 'https://calendly.com/michael-hierlife/spiritual-companionship',
            'reconnective-healing' => 'https://calendly.com/michael-hierlife/reconnective-healing',
            'qhht-session' => 'https://calendly.com/michael-hierlife/qhht-session',
            'meditation-session' => 'https://calendly.com/michael-hierlife/meditation-session',
        ];
        return $map[$slug] ?? '';
    }

    public static function get_event_type_definition(?int $product_id = null): array {
        $product_id = $product_id ?: get_the_ID();
        $product = $product_id ? wc_get_product($product_id) : false;
        if (!$product instanceof \WC_Product) return [];

        $uuid = (string) $product->get_meta('_cb_event_uuid', true);
        $scheduling_url = (string) $product->get_meta('_cb_scheduling_url', true);
        if (!$scheduling_url) $scheduling_url = self::known_calendly_url($product->get_slug());

        global $wpdb;
        if (!$uuid && $scheduling_url) {
            $uuid = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT uuid FROM {$wpdb->prefix}cb_event_types WHERE scheduling_url=%s LIMIT 1", $scheduling_url
            ));
        }
        if (!$uuid && $scheduling_url) {
            $rows = CB_API::instance()->query_event_types();
            foreach ((array) $rows as $row) {
                $url = (string) ($row['scheduling_url'] ?? '');
                if ($url && rtrim($url, '/') === rtrim($scheduling_url, '/')) {
                    $uuid = (string) ($row['uuid'] ?? '');
                    break;
                }
            }
        }
        if (!$uuid) return [];

        if (!$product->get_meta('_cb_event_uuid', true)) update_post_meta($product_id, '_cb_event_uuid', $uuid);
        if ($scheduling_url && !$product->get_meta('_cb_scheduling_url', true)) update_post_meta($product_id, '_cb_scheduling_url', esc_url_raw($scheduling_url));

        $cache_key = 'cb_event_definition_' . md5($uuid);
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached['uri'])) return $cached;

        $result = CB_API::instance()->get_event_type($uuid);
        $resource = is_array($result['resource'] ?? null) ? $result['resource'] : [];
        if ($resource) set_transient($cache_key, $resource, 5 * MINUTE_IN_SECONDS);
        return $resource;
    }

    public static function question_key(array $question): string {
        $name = trim((string) ($question['name'] ?? ''));
        $position = absint($question['position'] ?? 0);
        return 'cbq_' . substr(hash('sha256', $position . '|' . $name), 0, 20);
    }

    public static function location_key(array $location, int $index): string {
        return 'loc_' . $index . '_' . substr(hash('sha256', wp_json_encode($location)), 0, 12);
    }


    /**
     * Replace the normal archive/shop-loop add-to-cart action for meeting products.
     * Customers must enter the required booking form and choose a Calendly slot on
     * the single product page before the item can enter the cart.
     */
    public static function replace_loop_add_to_cart(string $html, $product, array $args = []): string {
        if (!$product instanceof \WC_Product || !self::is_meeting_product_id((int) $product->get_id())) {
            return $html;
        }

        $classes = isset($args['class']) ? (string) $args['class'] : 'button';
        $classes = trim($classes . ' cb-meeting-book-link');
        $text = self::is_request_product_id((int) $product->get_id())
            ? __('Request a session', 'calendly-bookings')
            : __('Book this meeting', 'calendly-bookings');

        return sprintf(
            '<a href="%1$s" class="%2$s" aria-label="%3$s">%4$s</a>',
            esc_url($product->get_permalink()),
            esc_attr($classes),
            esc_attr(sprintf(__('Book %s', 'calendly-bookings'), $product->get_name())),
            esc_html($text)
        );
    }

    /**
     * Change the single-product WooCommerce action label without changing the
     * underlying cart lifecycle. Hesychia is a request-a-session experience;
     * all other meeting products remain booking experiences.
     */
    public static function single_add_to_cart_text(string $text): string {
        return self::is_request_product() ? __('Request a session', 'calendly-bookings') : $text;
    }

    public static function archive_add_to_cart_text(string $text, $product): string {
        if ($product instanceof \WC_Product && self::is_meeting_product_id((int) $product->get_id())) {
            return self::is_request_product_id((int) $product->get_id())
                ? __('Request a session', 'calendly-bookings')
                : __('Book this meeting', 'calendly-bookings');
        }
        return $text;
    }

    public static function is_request_product_id(int $product_id): bool {
        if ($product_id <= 0) return false;
        $product = wc_get_product($product_id);
        return $product instanceof \WC_Product && strtolower($product->get_slug()) === 'hesychia';
    }

    public static function is_request_product(): bool {
        global $post;
        return $post && $post->post_type === 'product'
            ? self::is_request_product_id((int) $post->ID)
            : false;
    }

    /**
     * Server-side guard for every attempt to add a meeting product to the cart.
     * This prevents direct/loop URLs and forged requests from bypassing the
     * required booking form. On the single product page, the complete booking
     * payload is allowed through and is subsequently captured by CB_Checkout.
     */
    public static function validate_add_to_cart(bool $passed, int $product_id, int $quantity): bool {
        if (!self::is_meeting_product_id($product_id)) {
            return $passed;
        }

        $booking_product_id = isset($_POST['cb_booking_product_id'])
            ? absint($_POST['cb_booking_product_id'])
            : 0;
        $booking_nonce = isset($_POST['cb_booking_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['cb_booking_nonce']))
            : '';

        if ($booking_product_id !== $product_id || !wp_verify_nonce($booking_nonce, 'cb_add_meeting_' . $product_id)) {
            wc_add_notice(
                (self::is_request_product_id($product_id)
                    ? __('Please open the Hesychia session page and complete the request form before continuing.', 'calendly-bookings')
                    : __('Please open the meeting product page and complete the booking form before adding this meeting to your cart.', 'calendly-bookings')),
                'error'
            );
            return false;
        }

        $start_iso = isset($_POST['cb_meeting_start_iso'])
            ? sanitize_text_field(wp_unslash($_POST['cb_meeting_start_iso']))
            : '';
        $event_uuid = isset($_POST['cb_event_uuid'])
            ? sanitize_text_field(wp_unslash($_POST['cb_event_uuid']))
            : '';
        if (!$event_uuid && !empty($_POST['cb_event_type_uri'])) {
            $submitted_uri = esc_url_raw(wp_unslash($_POST['cb_event_type_uri']));
            if (preg_match('~/event_types/([^/]+)$~', $submitted_uri, $m)) {
                $event_uuid = sanitize_text_field($m[1]);
            }
        }
        $first_name = isset($_POST['billing_first_name'])
            ? sanitize_text_field(wp_unslash($_POST['billing_first_name']))
            : '';
        $last_name = isset($_POST['billing_last_name'])
            ? sanitize_text_field(wp_unslash($_POST['billing_last_name']))
            : '';
        $email = isset($_POST['billing_email'])
            ? sanitize_email(wp_unslash($_POST['billing_email']))
            : '';

        if (!$start_iso || !$event_uuid || !$first_name || !$last_name || !is_email($email)) {
            wc_add_notice(
                (self::is_request_product_id($product_id)
                    ? __('Please complete your name, email address, and select an available session date and time before submitting your request.', 'calendly-bookings')
                    : __('Please complete your name, email address, and select an available meeting date and time before adding this meeting to your cart.', 'calendly-bookings')),
                'error'
            );
            return false;
        }

        // Verify that the selected Event Type belongs to this product.
        $product = wc_get_product($product_id);
        $expected_uuid = $product instanceof \WC_Product
            ? (string) $product->get_meta('_cb_event_uuid', true)
            : '';
        if (!$expected_uuid && $product instanceof \WC_Product) {
            $definition = self::get_event_type_definition($product_id);
            $uri = (string) ($definition['uri'] ?? '');
            if ($uri && preg_match('~/event_types/([^/]+)$~', $uri, $m)) {
                $expected_uuid = (string) $m[1];
            }
        }
        if (!$expected_uuid || !hash_equals($expected_uuid, $event_uuid)) {
            wc_add_notice(
                __('The meeting configuration has changed. Please reload the product page and select an available time again.', 'calendly-bookings'),
                'error'
            );
            return false;
        }

        // The exact slot must be a valid ISO date-time. Availability itself is
        // revalidated by Calendly when the reconciliation worker creates the invitee.
        try {
            $selected = new \DateTimeImmutable($start_iso);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($selected <= $now) {
                throw new \Exception('past');
            }
        } catch (\Throwable $e) {
            wc_add_notice(
                __('Please select a valid future meeting time from the available Calendly slots.', 'calendly-bookings'),
                'error'
            );
            return false;
        }

        // Enforce required Calendly custom questions server-side as well as in the
        // browser. This keeps direct/forged requests from bypassing required fields.
        $definition = self::get_event_type_definition($product_id);
        $answers = isset($_POST['cb_calendly_answers']) && is_array($_POST['cb_calendly_answers'])
            ? wp_unslash($_POST['cb_calendly_answers'])
            : [];
        foreach ((array) ($definition['custom_questions'] ?? []) as $question) {
            if (empty($question['enabled']) || empty($question['required'])) continue;
            $name = trim((string) ($question['name'] ?? ''));
            if (!$name || preg_match('/^order\s*id(?:\b|\s*\()/i', $name)) continue;
            $key = self::question_key($question);
            $answer = $answers[$key] ?? null;
            $has_answer = is_array($answer)
                ? (bool) array_filter(array_map('trim', array_map('strval', $answer)))
                : trim((string) $answer) !== '';
            if (!$has_answer) {
                wc_add_notice(
                    sprintf(__('Please complete the required field: %s.', 'calendly-bookings'), $name),
                    'error'
                );
                return false;
            }
        }

        return $passed;
    }

    public static function output_before_cart(): void {
        if (self::is_meeting_product()) {
            echo self::render_calendly_form();
        }
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
        include_once CB_Constants::path('includes/frontend/view/index.php');
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
            include_once CB_Constants::path('includes/frontend/view/login-modal.php');
        }
    }



    /**
     * Determine if the current product belongs to the "meeting" or "meetings" category.
     *
     * @return bool
     */
    public static function is_meeting_product_id(int $product_id): bool {
        if ($product_id <= 0) return false;
        $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'slugs']);
        return in_array('meeting', $categories, true) || in_array('meetings', $categories, true);
    }

    public static function is_meeting_product(): bool {
        global $post;
        return $post && $post->post_type === 'product'
            ? self::is_meeting_product_id((int) $post->ID)
            : false;
    }

}
