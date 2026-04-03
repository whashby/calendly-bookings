<?php

namespace Calendly_Bookings\Utils;

if (!defined('ABSPATH')) {
    exit;
}

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

    /**
     * Convert any date or datetime string to UTC ISO 8601 format with trailing Z.
     *
     * @param string $input_time Any valid date or datetime string.
     * @return string ISO 8601 formatted UTC datetime string (e.g., 2026-03-26T13:05:00Z).
     */
    public static function to_iso_time(string $input_time): string {
        if (empty($input_time)) {
            return '';
        }

        try {
            // Create DateTime object from input using site timezone
            $dt = new \DateTime($input_time, wp_timezone());

            // Convert to UTC
            $dt->setTimezone(new \DateTimeZone('UTC'));

            // Format as ISO 8601 with trailing Z
            return $dt->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            return $input_time; // fallback to raw input
        }
    }
}
