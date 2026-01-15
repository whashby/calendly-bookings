# Copy of class-cb-shortcodes.php

```php
<?php
//includes/modules/class-cb-shortcodes.php

namespace Calendly_Bookings\Modules;
use Calendly_Bookings\CB_Constants;
use Calendly_Bookings\Utils\CB_Timezone_Converter;

if (!defined('ABSPATH')) exit;

final class CB_Shortcodes {

    /**
     * Initialize shortcode registration
     */
    public static function init(): void {
        add_action('init', [__CLASS__, 'register_shortcodes']);
    }

    /**
     * Register all plugin shortcodes
     */
    public static function register_shortcodes(): void {
        add_shortcode('cb_scheduled_meeting_details', [__CLASS__, 'scheduled_meeting_details']);
    }

    /**
     * Shortcode callback: [cb_scheduled_meeting_details]
     */
    public static function scheduled_meeting_details($atts = [], $content = null): string {
        global $wpdb;
        $table = $wpdb->prefix . 'cb_scheduled_events';
    

       // Collect query parameters
        $params = [
            'host'        => isset($_GET['assigned_to']) ? sanitize_text_field($_GET['assigned_to']) : '',
            'event_type_name'    => isset($_GET['event_type_name']) ? sanitize_text_field($_GET['event_type_name']) : '',
            'event_start_time'   => isset($_GET['event_start_time']) ? sanitize_text_field($_GET['event_start_time']) : '',
            'event_end_time'     => isset($_GET['event_end_time']) ? sanitize_text_field($_GET['event_end_time']) : '',
            'invitee_full_name'  => isset($_GET['invitee_full_name']) ? sanitize_text_field($_GET['invitee_full_name']) : '',
            'invitee_email'      => isset($_GET['invitee_email']) ? sanitize_email($_GET['invitee_email']) : '',
            'event_type_uuid'         => isset($_GET['event_type_uuid']) ? sanitize_text_field($_GET['event_type_uuid']) : '',
            'invitee_uuid'       => isset($_GET['invitee_uuid']) ? sanitize_text_field($_GET['invitee_uuid']) : '',
            'order_id'           => isset($_GET['answer_1']) ? sanitize_text_field($_GET['answer_1']) : '',
        ];
    
        // If no data, return nothing
        if (empty(array_filter($params))) {
            return '';
        }
    
        $order_id = $params['order_id'] ?? '';
        $start_iso = $params['event_start_time'] ?? '';
        
        if (empty($order_id) || empty($start_iso)) {
            if (!current_user_can('administrator')) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                nocache_headers();
                include get_404_template();
                exit;
            }
        }
    
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

        $meeting_details = json_decode($row['payload'], true);
        $meeting_details['cancel_url'] = $row['cancel_url'] ?? '';
        $meeting_details['reschedule_url'] = $row['reschedule_url'] ?? '';
        // Build HTML output
        ob_start();
        ?>
        <div class="cb-meeting-details">
            <?php if ($params['event_type_name']) : ?>
                <h2><?php echo esc_html($params['event_type_name']); ?></h2>
            <?php endif; ?>
    
            <?php if ($start_iso) : 
                $converter = new \Calendly_Bookings\Utils\CB_Timezone_Converter();
            ?>
                <p><strong>Date:</strong> <?php echo esc_html($converter->to_site_time($start_iso)); ?></p>
            <?php endif; ?>
    
            <?php if ($params['host']) : ?>
                <p><strong>Host:</strong> <?php echo esc_html($params['host']); ?></p>
            <?php endif; ?>
    
            <?php if ($params['invitee_full_name']) : ?>
                <p><strong>Invitee:</strong> <?php echo esc_html($params['invitee_full_name']); ?></p>
            <?php endif; ?>
    
            <?php if ($params['invitee_email']) : ?>
                <p><strong>Email:</strong> <?php echo esc_html($params['invitee_email']); ?></p>
            <?php endif; ?>
    
            <?php if (!empty($meeting_details)) : ?>
                <?php if (!empty($meeting_details['location']['type'])) : ?>
                    <p><strong>Meeting Type:</strong> <?php echo esc_html(ucwords($meeting_details['location']['type'])); ?></p>
                <?php endif; ?>
    
                <?php if (!empty($meeting_details['location']['join_url'])) : ?>
                    <p><strong>Join Link:</strong> <a href="<?php echo esc_url($meeting_details['location']['join_url']); ?>" target="_blank">Join Meeting</a></p>
                <?php endif; ?>
    
                <?php if (!empty($meeting_details['location']['password'])) : ?>
                    <p><strong>Password:</strong> <?php echo esc_html($meeting_details['location']['password']); ?></p>
                <?php endif; ?>
  
    <div class="cb-actions" style="display:flex;gap:15px;flex-wrap:wrap;">

        <?php if (!empty($meeting_details['cancel_url'])): ?>
            <a href="<?php echo esc_url($meeting_details['cancel_url']); ?>" class="button" style="background:#c62828;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;">
                Cancel Meeting
            </a>
        <?php endif; ?>

        <?php if (!empty($meeting_details['reschedule_url'])): ?>
            <a href="<?php echo esc_url($meeting_details['reschedule_url']); ?>" class="button" style="background:#0277bd;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;">
                Reschedule
            </a>
        <?php endif; ?>

    </div>
  
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }
}



```
