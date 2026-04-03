<?php
/**
 * Template Name: Meeting Scheduled (Plugin)
 */

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\Utils\CB_Timezone_Converter;

// ------------------------------------------------------------
// 1. Validate query vars (redirect to 404 unless admin)
// ------------------------------------------------------------
$order_id = $_GET['answer_1'] ?? '';
$start_iso = $_GET['start_time'] ?? '';

if (empty($uuid) || empty($start_iso)) {
    if (!current_user_can('administrator')) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        include get_404_template();
        exit;
    }
}

// ------------------------------------------------------------
// 2. Fetch scheduled event from plugin DB
// ------------------------------------------------------------
global $wpdb;
$table = $wpdb->prefix . 'cb_scheduled_events';

$row = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM $table WHERE order_id = %s", $order_id),
    ARRAY_A
);

if (!$row) {
    if (!current_user_can('administrator')) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        include get_404_template();
        exit;
    }
}

// ------------------------------------------------------------
// 3. Decode Calendly payload
// ------------------------------------------------------------
$payload   = json_decode($row['payload'], true);
$location  = $payload['location'] ?? [];
$invitee   = $payload['invitee'] ?? [];
$host      = $payload['event_memberships'][0] ?? [];

$start_utc = new DateTimeImmutable($row['start_time'], new DateTimeZone('UTC'));
$converter = new CB_Timezone_Converter();
$start_local = $converter->to_site_time($start_utc->format('Y-m-d H:i:s'));

// ------------------------------------------------------------
// 4. Extract meeting details
// ------------------------------------------------------------
$meeting_type = $location['type'] ?? '';
$zoom_join    = $location['join_url'] ?? '';
$zoom_pass    = $location['data']['password'] ?? '';

$physical_addr = $location['location'] ?? '';
$map_link      = $location['additional_info'] ?? '';

$cancel_url     = $row['cancel_url'] ?? '';
$reschedule_url = $row['reschedule_url'] ?? '';

get_header();
?>

<div class="cb-meeting-confirmation" style="max-width:700px;margin:40px auto;font-family:inherit;">

    <h1 style="margin-bottom:10px;"><?php echo esc_html($row['name']); ?></h1>


    <p><strong>Date:</strong> <?php echo esc_html(Date('l, F j, Y', strtotime($start_local))); ?></p>
    <p><strong>Time:</strong> <?php echo esc_html(Date('g:i A T', strtotime($start_local))); ?></p>

    <hr style="margin:25px 0;">

    <h2>Meeting Details</h2>

    <?php if ($meeting_type === 'zoom'): ?>
        <p><strong>Meeting Type:</strong> Zoom</p>
        <p><strong>Join Link:</strong> <a href="<?php echo esc_url($zoom_join); ?>" target="_blank">Join Meeting</a></p>

        <?php if (!empty($zoom_pass)): ?>
            <p><strong>Password:</strong> <?php echo esc_html($zoom_pass); ?></p>
        <?php endif; ?>

    <?php elseif ($meeting_type === 'physical'): ?>
        <p><strong>Meeting Type:</strong> In‑Person</p>
        <p><strong>Location:</strong> <?php echo esc_html($physical_addr); ?></p>

        <?php if (!empty($map_link)): ?>
            <p><a href="<?php echo esc_url($map_link); ?>" target="_blank">View on Map</a></p>
        <?php endif; ?>

    <?php else: ?>
        <p>Meeting details will be sent to your email.</p>
    <?php endif; ?>

    <hr style="margin:25px 0;">

    <h2>Participants</h2>

    <p><strong>Host:</strong> <?php echo esc_html($host['user_name'] ?? ''); ?></p>
    <p><strong>Invitee:</strong> <?php echo esc_html($invitee['name'] ?? ''); ?> (<?php echo esc_html($invitee['email'] ?? ''); ?>)</p>

    <hr style="margin:25px 0;">

    <div class="cb-actions" style="display:flex;gap:15px;flex-wrap:wrap;">

        <?php if (!empty($cancel_url)): ?>
            <a href="<?php echo esc_url($cancel_url); ?>" class="button" style="background:#c62828;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;">
                Cancel Meeting
            </a>
        <?php endif; ?>

        <?php if (!empty($reschedule_url)): ?>
            <a href="<?php echo esc_url($reschedule_url); ?>" class="button" style="background:#0277bd;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;">
                Reschedule
            </a>
        <?php endif; ?>

    </div>

</div>

<?php get_footer(); ?>
