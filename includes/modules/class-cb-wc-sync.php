<?php
// includes/modules/class-cb-wc-sync.php
namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) exit;

final class CB_WC_Sync {
    private const META_EVENT_UUID = '_cb_event_uuid';

    public static function init(): void {
        // Hooks for future automation (e.g., when product saved)
    }

    public static function link_product(int $product_id, string $event_uuid): bool {
        if ($product_id <= 0 || $event_uuid === '') return false;
        return update_post_meta($product_id, self::META_EVENT_UUID, sanitize_text_field($event_uuid)) !== false;
    }

    public static function unlink_product(int $product_id): bool {
        if ($product_id <= 0) return false;
        return delete_post_meta($product_id, self::META_EVENT_UUID);
    }

    public static function find_product_by_event(string $event_uuid): int {
        $q = new \WP_Query([
            'post_type'   => 'product',
            'post_status' => 'any',
            'meta_key'    => self::META_EVENT_UUID,
            'meta_value'  => sanitize_text_field($event_uuid),
            'fields'      => 'ids',
            'nopaging'    => true,
        ]);
        return (!empty($q->posts)) ? (int) $q->posts[0] : 0;
    }

    public static function list_links(): array {
        $q = new \WP_Query([
            'post_type'   => 'product',
            'post_status' => 'any',
            'meta_query'  => [[ 'key' => self::META_EVENT_UUID, 'compare' => 'EXISTS' ]],
            'fields'      => 'ids',
            'nopaging'    => true,
        ]);
        $out = [];
        foreach ($q->posts as $pid) {
            $out[] = [
                'product_id' => (int) $pid,
                'title'      => get_the_title($pid),
                'event_uuid' => (string) get_post_meta($pid, self::META_EVENT_UUID, true),
            ];
        }
        return $out;
    }

    public static function sync_from_event_type(array $event_type, ?int $product_id = null): int {
        $name     = sanitize_text_field($event_type['name'] ?? 'Calendly Event');
        $uuid     = sanitize_text_field($event_type['uuid'] ?? '');
        $duration = absint($event_type['duration'] ?? 0); // minutes
        $price    = isset($event_type['price']) ? floatval($event_type['price']) : 0.0;

        if ($uuid === '') return 0;

        $pid = $product_id ?: self::find_product_by_event($uuid);
        $post_data = [
            'post_title'   => $name,
            'post_type'    => 'product',
            'post_status'  => 'publish',
        ];

        if ($pid) {
            $post_data['ID'] = $pid;
            wp_update_post($post_data);
        } else {
            $pid = wp_insert_post($post_data);
            if (is_wp_error($pid) || !$pid) return 0;
            self::link_product($pid, $uuid);
        }

        // Update meta: duration, price
        update_post_meta($pid, '_cb_event_duration', $duration);
        update_post_meta($pid, '_price', $price);
        update_post_meta($pid, '_regular_price', $price);

        // Optionally: fetch availability and store as meta
        $api = new CB_API();
        $slots = $api->get_event_type_availability('https://api.calendly.com/event_types/' . $uuid, gmdate('c'));
        update_post_meta($pid, '_cb_event_availability', $slots['collection'] ?? []);

        return (int) $pid;
    }
}
