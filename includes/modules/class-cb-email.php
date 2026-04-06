<?php
namespace Calendly_Bookings\Modules;

final class CB_Email {
    public static function init(): void {
        add_filter('woocommerce_email_header', [__CLASS__, 'custom_header'], 10, 2);
        add_filter('woocommerce_email_footer', [__CLASS__, 'custom_footer'], 10, 1);
    }

    public static function custom_header($heading, $email): string {
        $header = get_option('cb_email_header');
        return $header ?: $heading;
    }

    public static function custom_footer($email): string {
        $footer = get_option('cb_email_footer');
        return $footer ?: $email;
    }

    public static function build_email_content(): string {
        $body = get_option('cb_email_body');
        return $body ?: '<p>Default Calendly Bookings Email Body</p>';
    }
}
