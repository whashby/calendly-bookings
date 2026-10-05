<?php
namespace Calendly_Bookings\Modules;
if (!defined('ABSPATH')) exit;
final class CB_Customer_Time {
    public static function valid(string $zone): string {
        return in_array($zone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true) ? $zone : '';
    }
    public static function viewer_timezone(): \DateTimeZone {
        $zone = self::valid(sanitize_text_field(wp_unslash((string) ($_COOKIE['cb_timezone'] ?? ''))));
        return $zone ? new \DateTimeZone($zone) : wp_timezone();
    }
    public static function order_timezone(\WC_Order $order): \DateTimeZone {
        $zone = self::valid((string) $order->get_meta('_cb_timezone', true));
        if (!$zone) {
            $webhook = json_decode((string) $order->get_meta('_cb_calendly_webhook_payload', true), true) ?: [];
            $payload = (array) ($webhook['payload'] ?? []);
            $invitee = (array) ($payload['invitee'] ?? $payload);
            $zone = self::valid((string) ($invitee['timezone'] ?? ''));
        }
        return $zone ? new \DateTimeZone($zone) : wp_timezone();
    }
    public static function format(string $utc, \DateTimeZone $zone, string $format = 'M j, Y g:i a'): string {
        try { $date = new \DateTimeImmutable($utc, new \DateTimeZone('UTC')); }
        catch (\Throwable $error) { return ''; }
        return wp_date($format, $date->getTimestamp(), $zone);
    }
    public static function html(string $utc): string {
        try { $date = new \DateTimeImmutable($utc, new \DateTimeZone('UTC')); }
        catch (\Throwable $error) { return ''; }
        return '<time data-cb-time="' . esc_attr($date->format('c')) . '">' . esc_html(self::format($utc, self::viewer_timezone())) . '</time>';
    }
    public static function init(): void {
        add_action('wp_enqueue_scripts', static function () {
            wp_enqueue_script('cb-customer-time', \Calendly_Bookings\CB_Constants::url('includes/frontend/assets/cb-customer-time.js'), [], \Calendly_Bookings\CB_Constants::VERSION, true);
        });
    }
}
