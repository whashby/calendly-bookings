<?php

if (!defined('ABSPATH')) {
    exit;
}

namespace Calendly_Bookings\Utils;

final class CB_Mail {
    /**
     * Send an email with HTML formatting, header, and footer.
     */
    public static function send($to, $subject, $body, $headers = []) {
        // Wrap body with HTML header/footer
        $html_body = self::format_html($body);

        // Default headers
        $default_headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        // Merge headers
        $headers = array_merge($default_headers, $headers);

        return wp_mail($to, $subject, $html_body, $headers);
    }

    /**
     * Format email body with header and footer.
     */
    private static function format_html($content) {
        $header = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#333;">
                     <h2 style="color:#444;">Calendly Bookings</h2><hr>';
        $footer = '<hr><p style="font-size:12px;color:#777;">&copy; ' . date('Y') . ' Calendly Bookings</p></div>';

        return $header . wpautop($content) . $footer;
    }

    /**
     * Send Walk-in email with specific headers.
     */
    public static function send_email($to, $subject, $body) {
        $headers = [
            'From: Michael A. Clarke <michael@hierlife.com>',
            'Reply-To: michael@hierlife.com',
        ];
        return self::send($to, $subject, $body, $headers);
    }
}
