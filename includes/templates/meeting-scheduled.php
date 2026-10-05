<?php
/**
 * Template Name: Meeting Scheduled (Plugin)
 *
 * Customer-facing confirmation. All appointment details are sourced exclusively
 * from the persisted Calendly webhook payload via the plugin shortcode.
 */
if (!defined('ABSPATH')) exit;

get_header();
?>
<div class="cb-meeting-confirmation" style="max-width:700px;margin:40px auto;font-family:inherit;">
    <?php echo do_shortcode('[cb_scheduled_meeting_details]'); ?>
</div>
<?php
get_footer();
