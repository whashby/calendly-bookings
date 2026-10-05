<?php
declare(strict_types=1);

namespace Calendly_Bookings\Modules;

use Calendly_Bookings\CB_Constants;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Commercial-grade email template service.
 *
 * Templates are stored as a single option to minimize option-table reads and
 * support future template additions without schema churn.
 */
final class CB_Email {

    public const TEMPLATE_CONFIRMED = 'booking_confirmed';
    public const TEMPLATE_CANCELED  = 'booking_canceled';
    public const TEMPLATE_ADMIN     = 'admin_notification';

    public static function defaults(): array {
        return [
            self::TEMPLATE_CONFIRMED => [
                'enabled' => true,
                'subject' => 'Your {event_name} booking is confirmed',
                'body'    => '<p>Hello {first_name},</p><p>Your <strong>{event_name}</strong> booking is confirmed.</p><p><strong>Date:</strong> {date}<br><strong>Time:</strong> {time} {timezone}<br><strong>Duration:</strong> {duration}</p><p><strong>Location:</strong> {location}</p><p><a href="{confirmation_url}">View your booking details</a></p><p>{join_link}</p><p>Thank you,<br>{site_name}</p>',
                'recipients' => 'customer',
            ],
            self::TEMPLATE_CANCELED => [
                'enabled' => true,
                'subject' => 'Your {event_name} booking has been canceled',
                'body'    => '<p>Hello {first_name},</p><p>Your <strong>{event_name}</strong> booking has been canceled.</p><p><strong>Original date:</strong> {date}<br><strong>Original time:</strong> {time} {timezone}</p><p>{cancel_reason}</p><p>{site_name}</p>',
                'recipients' => 'customer',
            ],
            self::TEMPLATE_ADMIN => [
                'enabled' => true,
                'subject' => 'Calendly booking: {event_name} — {status}',
                'body'    => '<p>A Calendly booking has been {status}.</p><p><strong>Customer:</strong> {first_name} {last_name}<br><strong>Email:</strong> {email}<br><strong>Event:</strong> {event_name}<br><strong>Date:</strong> {date}<br><strong>Time:</strong> {time} {timezone}<br><strong>Order:</strong> {order_id}</p><p><a href="{order_url}">Open WooCommerce order</a></p>',
                'recipients' => 'admin',
            ],
        ];
    }

    public static function init(): void {
        // Kept intentionally lightweight. The service is invoked only when
        // email settings are rendered or a booking webhook is reconciled.
    }

    public static function templates(): array {
        $saved = get_option(CB_Constants::OPT_EMAIL_TEMPLATES, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $defaults = self::defaults();
        $merged = [];
        foreach ($defaults as $key => $default) {
            $row = isset($saved[$key]) && is_array($saved[$key]) ? $saved[$key] : [];
            $merged[$key] = [
                'enabled'    => !empty($row) ? !empty($row['enabled']) : $default['enabled'],
                'subject'    => isset($row['subject']) ? (string) $row['subject'] : $default['subject'],
                'body'       => isset($row['body']) ? (string) $row['body'] : $default['body'],
                'recipients' => isset($row['recipients']) ? (string) $row['recipients'] : $default['recipients'],
            ];
        }

        return $merged;
    }

    public static function save_templates(array $templates): void {
        $allowed = array_keys(self::defaults());
        $clean = [];

        foreach ($allowed as $key) {
            $row = isset($templates[$key]) && is_array($templates[$key]) ? $templates[$key] : [];
            $clean[$key] = [
                'enabled' => !empty($row['enabled']),
                'subject' => sanitize_text_field(wp_unslash((string) ($row['subject'] ?? ''))),
                'body' => wp_kses_post(wp_unslash((string) ($row['body'] ?? ''))),
                'recipients' => in_array(($row['recipients'] ?? 'customer'), ['customer', 'admin'], true)
                    ? (string) $row['recipients'] : 'customer',
            ];
        }

        update_option(CB_Constants::OPT_EMAIL_TEMPLATES, $clean, false);
        update_option(CB_Constants::OPT_EMAIL_SETTINGS_VERSION, 1, false);
    }

    public static function tokens(): array {
        return [
            '{first_name}'      => 'Customer first name',
            '{last_name}'       => 'Customer last name',
            '{email}'           => 'Customer email',
            '{event_name}'      => 'Calendly event name',
            '{date}'            => 'Meeting date',
            '{time}'            => 'Meeting time',
            '{timezone}'        => 'Meeting timezone',
            '{duration}'        => 'Meeting duration',
            '{location}'        => 'Meeting location',
            '{meeting_details}' => 'Submitted meeting form details',
            '{join_link}'       => 'Join link',
            '{confirmation_url}'=> 'HIER Life confirmation page',
            '{cancel_url}'      => 'Calendly cancellation link',
            '{reschedule_url}'  => 'Calendly reschedule link',
            '{order_id}'        => 'WooCommerce order ID',
            '{order_url}'       => 'WooCommerce admin order URL',
            '{status}'          => 'Booking status',
            '{experience_term}' => 'Booking or request terminology for this product',
            '{cancel_reason}'   => 'Cancellation reason',
            '{site_name}'       => 'Site name',
            '{site_url}'        => 'Site URL',
        ];
    }

    public static function preview(string $template_key, array $context = []): array {
        $templates = self::templates();
        $template = $templates[$template_key] ?? reset($templates);
        $context = self::normalize_context($context);

        return [
            'subject' => self::replace_tokens($template['subject'], $context),
            'body' => self::replace_tokens($template['body'], $context, true),
        ];
    }

    public static function send_booking_email(\WC_Order $order, string $template_key, array $webhook = []): bool {
        $templates = self::templates();
        if (empty($templates[$template_key]['enabled'])) {
            return false;
        }

        $template = $templates[$template_key];
        $context = self::context_from_order($order, $webhook);
        $to = self::recipients($template['recipients'], $order);

        if (!$to) {
            return false;
        }

        $subject = self::replace_tokens($template['subject'], $context);
        $body = self::replace_tokens($template['body'], $context, true);
        if (!str_contains($template['body'], '{meeting_details}')) $body .= CB_Checkout::render_meeting_details($order);
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        $from = sanitize_email((string) get_option(CB_Constants::OPT_EMAIL_FROM, ''));
        $reply = sanitize_email((string) get_option(CB_Constants::OPT_EMAIL_REPLY_TO, ''));
        $bcc = self::email_list((string) get_option(CB_Constants::OPT_EMAIL_BCC, ''));

        if ($from) {
            $headers[] = 'From: ' . get_bloginfo('name') . ' <' . $from . '>';
        }
        if ($reply) {
            $headers[] = 'Reply-To: ' . $reply;
        }
        foreach ($bcc as $email) {
            $headers[] = 'Bcc: ' . $email;
        }

        return (bool) wp_mail($to, $subject, $body, $headers);
    }

    public static function send_once(\WC_Order $order, string $template_key, array $webhook = []): bool {
        $meta = '_cb_email_sent_' . sanitize_key($template_key);
        if ($order->get_meta($meta, true)) {
            return true;
        }

        $sent = self::send_booking_email($order, $template_key, $webhook);
        if ($sent) {
            $order->update_meta_data($meta, gmdate('c'));
            $order->save();
        }

        return $sent;
    }

    public static function build_email_content(): string {
        $preview = self::preview(self::TEMPLATE_CONFIRMED);
        return $preview['body'];
    }

    public static function custom_header($heading, $email): string {
        $header = get_option(CB_Constants::OPT_EMAIL_HEADER, '');
        return $header !== '' ? (string) $header : (string) $heading;
    }

    public static function custom_footer($email): string {
        $footer = get_option(CB_Constants::OPT_EMAIL_FOOTER, '');
        return $footer !== '' ? (string) $footer : '';
    }

    private static function recipients(string $type, \WC_Order $order): array {
        if ($type === 'customer') {
            $email = sanitize_email($order->get_billing_email());
            return $email ? [$email] : [];
        }

        $configured = (string) get_option(CB_Constants::OPT_EMAIL_TO, '');
        if ($configured === '') {
            $configured = (string) get_option('admin_email', '');
        }
        return self::email_list($configured);
    }

    private static function email_list(string $value): array {
        $emails = [];
        foreach (preg_split('/[,;\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            $email = sanitize_email($candidate);
            if ($email && is_email($email)) {
                $emails[] = $email;
            }
        }
        return array_values(array_unique($emails));
    }

    private static function normalize_context(array $context): array {
        $defaults = [
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'event_name' => 'Meeting',
            'date' => '',
            'time' => '',
            'timezone' => wp_timezone_string() ?: 'UTC',
            'duration' => '',
            'location' => '',
            'meeting_details' => '',
            'join_link' => '',
            'confirmation_url' => '',
            'cancel_url' => '',
            'reschedule_url' => '',
            'order_id' => '',
            'order_url' => '',
            'status' => '',
            'cancel_reason' => '',
            'site_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'site_url' => home_url('/'),
        ];
        return array_merge($defaults, $context);
    }

    private static function context_from_order(\WC_Order $order, array $webhook = []): array {
        $payload = is_array($webhook['payload'] ?? null) ? $webhook['payload'] : [];
        $invitee = is_array($payload['invitee'] ?? null) ? $payload['invitee'] : $payload;
        $event = is_array($payload['event'] ?? null) ? $payload['event'] : [];
        if (!$event && is_array($payload['scheduled_event'] ?? null)) {
            $event = $payload['scheduled_event'];
        }

        $start = (string) ($event['start_time'] ?? $invitee['start_time'] ?? $order->get_meta('_cb_meeting_start_iso', true));
        $tz = CB_Customer_Time::valid((string) ($invitee['timezone'] ?? '')) ?: CB_Customer_Time::order_timezone($order)->getName();
        if ($tz === '') $tz = wp_timezone_string() ?: 'UTC';
        $date = $time = '';
        if ($start) {
            try {
                $dt = new \DateTimeImmutable($start);
                $dt = $dt->setTimezone(new \DateTimeZone($tz));
                $date = wp_date(get_option('date_format'), $dt->getTimestamp(), new \DateTimeZone($tz));
                $time = wp_date(get_option('time_format'), $dt->getTimestamp(), new \DateTimeZone($tz));
            } catch (\Throwable $e) {
                // Leave fields empty rather than fabricate a value.
            }
        }

        $event_name = (string) ($event['name'] ?? $order->get_meta('_cb_calendly_event_name', true));
        if ($event_name === '') {
            $items = $order->get_items();
            $first_item = reset($items);
            $event_name = $first_item ? (string) $first_item->get_name() : 'Meeting';
        }

        $location = $event['location']['location'] ?? $event['location']['type'] ?? $order->get_meta('_cb_meeting_location_detail_text', true);
        if (is_array($location)) {
            $location = wp_json_encode($location);
        }

        $join = (string) ($event['location']['join_url'] ?? $invitee['scheduling_url'] ?? $order->get_meta('_cb_calendly_join_url', true));
        $confirmation = method_exists('Calendly_Bookings\\Modules\\CB_Checkout', 'get_confirmation_url')
            ? CB_Checkout::get_confirmation_url($order) : '';
        $cancel = (string) ($invitee['cancel_url'] ?? $order->get_meta('_cb_calendly_cancel_url', true));
        $reschedule = (string) ($invitee['reschedule_url'] ?? $order->get_meta('_cb_calendly_reschedule_url', true));
        $duration = $event['duration'] ?? $order->get_meta('_cb_calendly_duration', true);

        $status = (string) ($webhook['event'] ?? $order->get_meta('_cb_calendly_booking_status', true));
        $reason = (string) ($invitee['cancellation']['reason'] ?? $invitee['canceled_reason'] ?? '');

        $experience_term = 'booking';
        foreach ($order->get_items() as $item) {
            $item_product = $item->get_product();
            if ($item_product instanceof \WC_Product && strtolower($item_product->get_slug()) === 'hesychia') {
                $experience_term = 'session request';
                break;
            }
        }

        return self::normalize_context([
            'first_name' => (string) $order->get_billing_first_name(),
            'last_name' => (string) $order->get_billing_last_name(),
            'email' => (string) $order->get_billing_email(),
            'event_name' => $event_name,
            'date' => $date,
            'time' => $time,
            'timezone' => $tz,
            'duration' => $duration !== '' ? $duration . (is_numeric($duration) ? ' minutes' : '') : '',
            'location' => wp_strip_all_tags((string) $location) ?: (CB_Checkout::meeting_details($order)['Location'] ?? ''),
            'meeting_details' => CB_Checkout::render_meeting_details($order),
            'join_link' => $join ? '<p><a href="' . esc_url($join) . '">Join meeting</a></p>' : '',
            'confirmation_url' => $confirmation,
            'cancel_url' => $cancel,
            'reschedule_url' => $reschedule,
            'order_id' => $order->get_order_number(),
            'order_url' => admin_url('post.php?post=' . $order->get_id() . '&action=edit'),
            'status' => $status,
            'cancel_reason' => $reason,
        ]);
    }

    private static function replace_tokens(string $text, array $context, bool $html = false): string {
        $replace = [];
        foreach (self::tokens() as $token => $label) {
            $key = trim($token, '{}');
            $value = (string) ($context[$key] ?? '');
            $replace[$token] = !$html || in_array($key, ['join_link','meeting_details'], true) ? $value : (str_ends_with($key, '_url') ? esc_url($value) : nl2br(esc_html($value)));
        }
        return strtr($text, $replace);
    }
}
