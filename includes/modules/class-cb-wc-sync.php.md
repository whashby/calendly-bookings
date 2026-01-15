# Copy of class-cb-wc-sync.php

```php
<?php
namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) exit;

final class CB_WC_Sync {
    private const META_EVENT_UUID          = '_cb_event_uuid';
    private const META_EVENT_SCHEDULING_URL= '_cb_scheduling_url';

    public static function init(): void {
        // Reserved for future automation hooks (e.g. product save)
    }

    public static function link_product(int $product_id, string $event_uuid, ?string $scheduling_url = null): bool {
        if ($product_id <= 0 || $event_uuid === '' || $scheduling_url == null || $scheduling_url === '' ) return false;
        update_post_meta($product_id, self::META_EVENT_UUID, sanitize_text_field($event_uuid));
        update_post_meta($product_id, self::META_EVENT_SCHEDULING_URL, esc_url_raw($scheduling_url));

        return true;
    }

    public static function unlink_product(int $product_id): bool {
        if ($product_id <= 0) return false;
        $a = delete_post_meta($product_id, self::META_EVENT_UUID);
        $b = delete_post_meta($product_id, self::META_EVENT_SCHEDULING_URL);
        return (bool) ($a || $b);
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
            'meta_query'  => [
                ['key' => self::META_EVENT_UUID, 'compare' => 'EXISTS'],
                ['key' => self::META_EVENT_SCHEDULING_URL, 'compare' => 'EXISTS']
            ],
            'fields'      => 'ids',
            'nopaging'    => true,
        ]);
        $out = [];
        foreach ($q->posts as $pid) {
            $out[] = [
                'product_id'     => (int) $pid,
                'title'          => get_the_title($pid),
                'event_uuid'     => (string) get_post_meta($pid, self::META_EVENT_UUID, true),
                'scheduling_url' => (string) get_post_meta($pid, self::META_EVENT_SCHEDULING_URL, true),
            ];
        }
        return $out;
    }

    public static function sync_from_event_type(array $event_type, ?int $product_id = null): int {
        $name           = sanitize_text_field($event_type['name'] ?? 'Calendly Event');
        $uuid           = sanitize_text_field($event_type['uuid'] ?? '');
        $scheduling_url = esc_url_raw($event_type['scheduling_url'] ?? '');
        $duration       = absint($event_type['duration'] ?? 0); // minutes
        $price          = isset($event_type['price']) ? floatval($event_type['price']) : 0.0;

        if ($uuid === '') return 0;

        $pid = $product_id ?: self::find_product_by_event($uuid);
        $post_data = [
            'post_title'  => $name,
            'post_type'   => 'product',
            'post_status' => 'publish',
        ];

        if ($pid) {
            $post_data['ID'] = $pid;
            wp_update_post($post_data);
        } else {
            $pid = wp_insert_post($post_data);
            if (is_wp_error($pid) || !$pid) return 0;
            self::link_product($pid, $uuid, $scheduling_url);
        }

        // Update meta
        update_post_meta($pid, '_cb_event_duration', $duration);
        update_post_meta($pid, '_price', $price);
        update_post_meta($pid, '_regular_price', $price);
        if (!empty($scheduling_url)) {
            update_post_meta($pid, self::META_EVENT_SCHEDULING_URL, $scheduling_url);
        }

        // Optionally: fetch availability and store as meta
        $api   = new CB_API();
        $slots = $api->get_event_type_availability('https://api.calendly.com/event_types/' . $uuid, gmdate('c'));
        update_post_meta($pid, '_cb_event_availability', $slots['collection'] ?? []);

        return (int) $pid;
    }
}
```
