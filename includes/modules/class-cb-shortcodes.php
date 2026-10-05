<?php
declare(strict_types=1);
namespace Calendly_Bookings\Modules;
if (!defined('ABSPATH')) exit;
final class CB_Shortcodes {
    public static function init(): void { add_action('init', [__CLASS__, 'register_shortcodes']); }
    public static function register_shortcodes(): void { add_shortcode('cb_scheduled_meeting_details', [__CLASS__, 'scheduled_meeting_details']); }
    public static function scheduled_meeting_details($atts = [], $content = null): string {
        global $wpdb; $table=$wpdb->prefix.'cb_scheduled_events'; $token=sanitize_text_field((string)($_GET['token']??'')); $invitee_uuid=sanitize_text_field((string)($_GET['invitee_uuid']??'')); $order_id=absint($_GET['answer_1']??0); $row=null;
        if($token) $order_id=absint($wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_cb_meeting_confirmation_token' AND meta_value=%s LIMIT 1",$token)));
        // The order's raw webhook payload is the authoritative customer-facing source.
        if($order_id) {
            $order = wc_get_order($order_id);
            if($order) {
                $raw_order_webhook = (string)$order->get_meta('_cb_calendly_webhook_payload', true);
                if($raw_order_webhook !== '') {
                    $webhook = json_decode($raw_order_webhook, true);
                    if(is_array($webhook)) $row = ['webhook_event'=>(string)$order->get_meta('_cb_calendly_webhook_event', true), 'name'=>''];
                }
            }
        }
        if(empty($webhook) && $order_id) $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id=%d AND webhook_event LIKE 'invitee.%%' ORDER BY webhook_received_at DESC,id DESC LIMIT 1",$order_id),ARRAY_A);
        if(empty($webhook) && $invitee_uuid) $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE payload LIKE %s AND webhook_event LIKE 'invitee.%%' ORDER BY webhook_received_at DESC,id DESC LIMIT 1",'%'.$wpdb->esc_like($invitee_uuid).'%'),ARRAY_A);
        if(empty($webhook)) {
            if(!$row||empty($row['webhook_event'])||empty($row['payload'])) return self::pending_confirmation();
            $webhook=json_decode((string)$row['payload'],true);
        }
        if(!is_array($webhook)) return self::pending_confirmation(); $payload=is_array($webhook['payload']??null)?$webhook['payload']:[]; $invitee=is_array($payload['invitee']??null)?$payload['invitee']:$payload; $event=is_array($payload['event']??null)?$payload['event']:[];
        // Deliberately do not query Calendly here. Customer-facing data comes only from the persisted webhook payload.
        $event_name=sanitize_text_field((string)($webhook['event']??$row['webhook_event']));
        $request_session = false;
        if ($order_id) {
            $confirmation_order = wc_get_order($order_id);
            if ($confirmation_order) {
                foreach ($confirmation_order->get_items() as $confirmation_item) {
                    $confirmation_product = $confirmation_item->get_product();
                    if ($confirmation_product instanceof \WC_Product && strtolower($confirmation_product->get_slug()) === 'hesychia') {
                        $request_session = true;
                        break;
                    }
                }
            }
        }
        if (!$request_session && stripos($session ?? '', 'hesychia') !== false) $request_session = true;
        $start=(string)($event['start_time']??$payload['start_time']??$invitee['start_time']??''); $end=(string)($event['end_time']??$payload['end_time']??$invitee['end_time']??''); $session=(string)($event['name']??$payload['event_name']??$row['name']??''); $location=is_array($event['location']??null)?$event['location']:(is_array($payload['location']??null)?$payload['location']:[]); $members=is_array($event['event_memberships']??null)?$event['event_memberships']:[]; $host=(string)($members[0]['user_name']??$members[0]['user_email']??''); $iname=(string)($invitee['name']??''); $iemail=(string)($invitee['email']??''); $join=(string)($location['join_url']??''); $password=(string)($location['password']??''); $loc=(string)($location['location']??$location['additional_info']??''); $kind=strtolower((string)($location['kind']??$location['type']??'')); $physical=in_array($kind,['physical','custom'],true)&&!$join; $status=($event_name==='invitee.canceled'||($invitee['status']??'')==='canceled')?'Canceled':'Confirmed by Calendly';
        $customer_zone = CB_Customer_Time::valid((string) ($invitee['timezone'] ?? ''));
        $customer_zone = $customer_zone ? new \DateTimeZone($customer_zone) : CB_Customer_Time::viewer_timezone();
        ob_start(); ?>
        <div class="cb-meeting-details cb-webhook-confirmation" data-source="calendly-webhook">
          <div class="cb-confirmation-header"><p class="cb-confirmation-eyebrow"><?php echo esc_html($request_session ? __('Session request confirmation', 'calendly-bookings') : __('Booking confirmation', 'calendly-bookings')); ?></p><?php if($session): ?><h1><?php echo esc_html($session); ?></h1><?php endif; ?><p class="cb-confirmation-status"><?php echo esc_html($status); ?></p></div>
          <section><h2><?php esc_html_e('Session Details','calendly-bookings'); ?></h2><?php if($start): ?><p><strong><?php esc_html_e('Date:','calendly-bookings'); ?></strong> <?php echo '<time data-cb-time="' . esc_attr($start) . '" data-cb-time-mode="date">' . esc_html(CB_Customer_Time::format($start,$customer_zone,'l, F j, Y')) . '</time>'; ?></p><p><strong><?php esc_html_e('Time:','calendly-bookings'); ?></strong> <?php echo '<time data-cb-time="' . esc_attr($start) . '" data-cb-time-mode="time">' . esc_html(CB_Customer_Time::format($start,$customer_zone,'g:i A T')) . '</time>'; ?></p><?php endif; ?><?php if($start&&$end): ?><p><strong><?php esc_html_e('Duration:','calendly-bookings'); ?></strong> <?php echo esc_html(max(1,(int)round((strtotime($end)-strtotime($start))/60)).' minutes'); ?></p><?php endif; ?></section>
          <?php if($kind||$loc||$join): ?><section><h2><?php echo esc_html($request_session ? __('Session Details', 'calendly-bookings') : __('Meeting Details', 'calendly-bookings')); ?></h2><?php if($kind): ?><p><strong><?php esc_html_e('Meeting Type:','calendly-bookings'); ?></strong> <?php echo esc_html(ucwords(str_replace('_',' ',$kind))); ?></p><?php endif; ?><?php if($loc): ?><p><strong><?php esc_html_e('Location:','calendly-bookings'); ?></strong> <?php echo nl2br(esc_html($loc)); ?></p><?php endif; ?><?php if($join): ?><p><strong><?php esc_html_e('Join:','calendly-bookings'); ?></strong> <a href="<?php echo esc_url($join); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo esc_html($request_session ? __('Join Session', 'calendly-bookings') : __('Join Meeting', 'calendly-bookings')); ?></a></p><?php endif; ?><?php if($password): ?><p><strong><?php esc_html_e('Password:','calendly-bookings'); ?></strong> <?php echo esc_html($password); ?></p><?php endif; ?><?php if($physical&&$loc): $map='https://www.google.com/maps?q='.rawurlencode($loc).'&output=embed'; ?><div class="cb-map-wrapper"><iframe title="<?php esc_attr_e('Meeting location map','calendly-bookings'); ?>" src="<?php echo esc_url($map); ?>" loading="lazy"></iframe></div><p><a href="<?php echo esc_url('https://www.google.com/maps/search/?api=1&query='.rawurlencode($loc)); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open location in Google Maps','calendly-bookings'); ?></a></p><?php endif; ?></section><?php endif; ?>
          <?php if($host||$iname||$iemail): ?><section><h2><?php esc_html_e('Participants','calendly-bookings'); ?></h2><?php if($host): ?><p><strong><?php esc_html_e('Host:','calendly-bookings'); ?></strong> <?php echo esc_html($host); ?></p><?php endif; ?><?php if($iname): ?><p><strong><?php esc_html_e('Invitee:','calendly-bookings'); ?></strong> <?php echo esc_html($iname); ?></p><?php endif; ?><?php if($iemail): ?><p><strong><?php esc_html_e('Email:','calendly-bookings'); ?></strong> <?php echo esc_html($iemail); ?></p><?php endif; ?></section><?php endif; ?>
          <div class="cb-actions"><?php if(!empty($invitee['cancel_url'])): ?><a class="button" href="<?php echo esc_url($invitee['cancel_url']); ?>" rel="nofollow"><?php echo esc_html($request_session ? __('Cancel Session', 'calendly-bookings') : __('Cancel Meeting', 'calendly-bookings')); ?></a><?php endif; ?><?php if(!empty($invitee['reschedule_url'])): ?><a class="button" href="<?php echo esc_url($invitee['reschedule_url']); ?>" rel="nofollow"><?php esc_html_e('Reschedule','calendly-bookings'); ?></a><?php endif; ?></div>
        </div>
        <?php return (string)ob_get_clean();
    }
    private static function pending_confirmation(): string { return '<div class="cb-meeting-details cb-meeting-pending"><h2>'.esc_html__('Booking confirmation pending','calendly-bookings').'</h2><p>'.esc_html__('Your order was received. We are waiting for the Calendly confirmation webhook. Please refresh this page shortly.','calendly-bookings').'</p></div>'; }
}
