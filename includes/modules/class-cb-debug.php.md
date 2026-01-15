# Copy of class-cb-debug.php

```php
<?php
namespace Calendly_Bookings\Modules;

class CB_Debug {

    /**
     * Register hooks.
     */
    public static function init(): void {
        add_action('init', [__CLASS__, 'debug_product_meta']);
        add_action('init', [__CLASS__, 'debug_event_types']);
        add_action('init', [__CLASS__, 'debug_orders']);
    }

    /**
     * Query all products and dump their meta.
     */
    public static function debug_product_meta(): void {
        if (!current_user_can('manage_options')) return;
        if (!isset($_GET['debug_products'])) return;

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ];

        $products = new \WP_Query($args);

        if ($products->have_posts()) {
            echo '<div style="background:#fff; padding:20px;">';
            echo '<h2>Product Meta Debug</h2>';
            foreach ($products->posts as $product_id) {
                echo '<h3>Product ID: ' . esc_html($product_id) . ' — ' . esc_html(get_the_title($product_id)) . '</h3>';
                $all_meta = get_post_meta($product_id);
                echo '<pre>' . esc_html(print_r($all_meta, true)) . '</pre>';
            }
            echo '</div>';
            exit;
        }
    }

    /**
     * Dump contents of cb_event_types table.
     */
    public static function debug_event_types(): void {
        if (!current_user_can('manage_options')) return;
        if (!isset($_GET['debug_events'])) return;

        global $wpdb;
        $table = $wpdb->prefix . 'cb_event_types';
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY name ASC", ARRAY_A);

        echo '<div style="background:#fff; padding:20px;">';
        echo '<h2>Event Types Table Debug</h2>';
        if (empty($rows)) {
            echo '<p>No event types found.</p>';
        } else {
            foreach ($rows as $row) {
                echo '<h3>' . esc_html($row['name']) . ' (' . esc_html($row['uuid']) . ')</h3>';
                echo '<ul>';
                echo '<li><strong>Duration:</strong> ' . esc_html($row['duration']) . ' minutes</li>';
                echo '<li><strong>Scheduling URL:</strong> ' . esc_html($row['scheduling_url']) . '</li>';
                echo '<li><strong>Product ID:</strong> ' . esc_html($row['product_id']) . '</li>';
                echo '<li><strong>URI:</strong> ' . esc_html($row['uri']) . '</li>';
                echo '</ul>';
                echo '<details><summary>Meta JSON</summary><pre>' . esc_html($row['meta']) . '</pre></details>';
                echo '<hr>';
            }
        }
        echo '</div>';
        exit;
    }

    /**
     * Dump WooCommerce orders and their meta.
     */
    public static function debug_orders(): void {
        if (!current_user_can('manage_options')) return;
        if (!isset($_GET['debug_orders'])) return;

        $args = [
            'post_type'      => 'shop_order',
            'posts_per_page' => 20, // limit for readability
            'post_status'    => array_keys(wc_get_order_statuses()),
            'fields'         => 'ids',
        ];

        $orders = new \WP_Query($args);

        echo '<div style="background:#fff; padding:20px;">';
        echo '<h2>Orders Debug</h2>';

        if ($orders->have_posts()) {
            foreach ($orders->posts as $order_id) {
                $order = wc_get_order($order_id);
                echo '<h3>Order #' . esc_html($order_id) . ' — Status: ' . esc_html($order->get_status()) . '</h3>';
                echo '<p><strong>Date:</strong> ' . esc_html($order->get_date_created()) . '</p>';
                echo '<p><strong>Total:</strong> ' . esc_html($order->get_formatted_order_total()) . '</p>';

                $all_meta = get_post_meta($order_id);
                echo '<details><summary>Meta Data</summary><pre>' . esc_html(print_r($all_meta, true)) . '</pre></details>';
                echo '<hr>';
            }
        } else {
            echo '<p>No orders found.</p>';
        }

        echo '</div>';
        exit;
    }
}

```
