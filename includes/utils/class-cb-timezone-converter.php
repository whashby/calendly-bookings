<?php
//includes/utils/class-cb-timezone-converter.php
namespace Calendly_Bookings\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Utility class for converting UTC times to the site's timezone.
 */
final class CB_Timezone_Converter {

    /**
     * Convert a UTC datetime string to the site's timezone.
     *
     * @param string $utc_time Datetime string stored in UTC (Y-m-d H:i:s).
     * @param string $format   Output format (default: WordPress date/time format).
     * @return string          Localized datetime string.
     */
    public static function to_site_time(string $utc_time, string $format = ''): string {
        if (empty($utc_time)) {
            return '—';
        }

        try {
            // Create DateTime in UTC
            $dt = new \DateTime($utc_time, new \DateTimeZone('UTC'));

            // Get site timezone (falls back to UTC if not set)
            $tz = wp_timezone(); // WordPress helper returns DateTimeZone
            $dt->setTimezone($tz);

            // Use WP date/time format if none provided
            if (empty($format)) {
                $format = get_option('date_format') . ' ' . get_option('time_format');
            }

            return $dt->format($format);
        } catch (\Exception $e) {
            return $utc_time; // fallback to raw UTC string
        }
    }
}
