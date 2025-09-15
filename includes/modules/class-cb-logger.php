<?php
// includes/modules/class-cb-logger.php
namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) exit;

final class CB_Logger {
    public static function init(): void {}

    public static function log(string $event, array $ctx = []): void {
        $ctx = array_intersect_key($ctx, array_flip(['endpoint','method','status','duration_ms','note','uri','count','error','topic']));
        $line = sprintf('[CalendlyBookings] %s %s', $event, wp_json_encode($ctx));
        error_log($line);
        do_action('cb_log', $event, $ctx);
    }
}
