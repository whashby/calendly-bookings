<?php
namespace Calendly_Bookings\Modules;
if (!defined('ABSPATH')) exit;
/** Keep diagnostics out of FastCGI stderr, which IIS may turn into HTTP 500. */
final class CB_Logger {
    public static function debug(string $message): void {
        if (!function_exists('wc_get_logger')) return;
        try {
            wc_get_logger()->debug($message, ['source' => 'calendly-bookings']);
        } catch (\Throwable $error) {
            // Diagnostic logging must never interrupt checkout or emit response output.
        }
    }
}
