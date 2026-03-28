<?php
namespace Calendly_Bookings\Admin\Views;

if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
  <h1><?php esc_html_e('Scheduled Events', 'calendly-bookings'); ?></h1>
  <p><?php esc_html_e('Manage upcoming and past scheduled events.', 'calendly-bookings'); ?></p>

  <div id="cb-admin-notices"></div>

  <div id="cb-scheduled-events">
    <?php echo \Calendly_Bookings\Modules\CB_Admin::view('scheduled-events/filters', [
      'filters' => $filters
    ]); ?>

    <?php echo \Calendly_Bookings\Modules\CB_Admin::view('scheduled-events/pagination', [
      'total'   => $total,
      'page'    => $page,
      'limit'   => $limit,
      'orderby' => $orderby,
      'order'   => $order,
      'filters' => $filters
    ]); ?>
    <?php echo \Calendly_Bookings\Modules\CB_Admin::view('scheduled-events/table', [
      'events'  => $events,
      'orderby' => $orderby,
      'order'   => $order
    ]); ?>

    <?php echo \Calendly_Bookings\Modules\CB_Admin::view('scheduled-events/pagination', [
      'total'   => $total,
      'page'    => $page,
      'limit'   => $limit,
      'orderby' => $orderby,
      'order'   => $order,
      'filters' => $filters
    ]); ?>

        <!-- Shared Invitee History ThickBox -->
<?php 
    $unique_invitees = [];
    foreach ($events as $event) : ?>
        <?php 
        if( !empty($event['invitee_name']) ) : 
            $unique_invitees[$event['invitee_email']] = $event['invitee_name'];
        endif; 
    endforeach; 
?>


    <!-- Bulk Update -->
    <div id="cb-bulk-update-content-modal" style="display:none;">
        <h2><?php esc_html_e('Update Event Status', 'calendly-bookings'); ?></h2>
        <div class="cb-thickbox-form">
            <p><?php esc_html_e('Select a new status for the selected events:', 'calendly-bookings'); ?></p>
            <div class="cb-status-options">
                <label><input type="radio" name="bulk-status" value="active"> <?php esc_html_e('Active', 'calendly-bookings'); ?></label>
                <label><input type="radio" name="bulk-status" value="canceled"> <?php esc_html_e('Canceled', 'calendly-bookings'); ?></label>
                <label><input type="radio" name="bulk-status" value="completed"> <?php esc_html_e('Completed', 'calendly-bookings'); ?></label>
            </div>
            <div class="cb-thickbox-actions">
                <button type="submit" id="cb-bulk-update-submit" class="button button-primary cb-save-btn"><?php esc_html_e('Update', 'calendly-bookings'); ?></button>
                <button type="button" id="cb-bulk-update-cancel" class="button cb-cancel-btn"><?php esc_html_e('Cancel', 'calendly-bookings'); ?></button>
            </div>
        </div>
    </div>
  </div>



<div id="cb-walkin-modal" style="display:none;">
    <h2><?php esc_html_e('New Invitee', 'calendly-bookings'); ?></h2>
    <form id="cb-walkin-form" class="cb-thickbox-form">
        
        <div class="cb-field-row">
            <label for="firstname"><?php esc_html_e('First Name', 'calendly-bookings'); ?>
                <input type="text" id="firstname" name="firstname" class="regular-text" required>
            </label>
            <label for="lastname"><?php esc_html_e('Last Name', 'calendly-bookings'); ?>
                <input type="text" id="lastname" name="lastname" class="regular-text" required>
            </label>
        </div>
        
        <div class="cb-field-row">
            <label for="email"><?php esc_html_e('Email', 'calendly-bookings'); ?>
                <input type="email" id="email" name="email" class="regular-text" required>
            </label>
        </div>
        
        <div class="cb-field-row">
            <label for="initial_session"><?php esc_html_e('Initial Session', 'calendly-bookings'); ?>
                <select id="initial_session" name="initial_session">
                    <option value=""><?php esc_html_e('Select a session', 'calendly-bookings'); ?></option>
                </select>
            </label>
        </div>
        
        <div class="cb-field-row">
            <label for="initial_date"><?php esc_html_e('Date', 'calendly-bookings'); ?>
                <input type="date" id="initial_date" name="initial_date" class="regular-text" required>
            </label>

            <label for="initial_time"><?php esc_html_e('Time', 'calendly-bookings'); ?>
                <input type="time" id="initial_time" name="initial_time" class="regular-text" required>
            </label>
        </div>
        
        <label for="location"><?php esc_html_e('Location', 'calendly-bookings'); ?>
            <select id="location" name="location"></select>
        </label>
        
        <h3><?php esc_html_e('Notes', 'calendly-bookings'); ?></h3>
        <label for="notes-discussed"><?php esc_html_e('What was discussed', 'calendly-bookings'); ?></label>
        <textarea id="notes-discussed" name="notes-discussed" class="large-text"></textarea>
        
        <label for="notes-guidance"><?php esc_html_e('Guidance provided', 'calendly-bookings'); ?></label>
        <textarea id="notes-guidance" name="notes-guidance" class="large-text"></textarea>
        
        <label for="notes-follow-up"><?php esc_html_e('Follow-up actions', 'calendly-bookings'); ?></label>
        <textarea id="notes-follow-up" name="notes-follow-up" class="large-text"></textarea>
        
        <h3><?php esc_html_e('Follow-up Session', 'calendly-bookings'); ?></h3>
        <div class="cb-field-row">
            <label for="followup_session"><?php esc_html_e('Event Type', 'calendly-bookings'); ?>
                <select id="followup_session" name="followup_session">
                    <option value=""><?php esc_html_e('Select a session', 'calendly-bookings'); ?></option>
                </select>
            </label>
        </div>
        
        <div id="next-available-slot" style="margin-bottom:10px; font-weight:bold; color:#0073aa;"></div>
        
        <div class="cb-field-row">
            <label for="followup_date"><?php esc_html_e('Follow-up Date', 'calendly-bookings'); ?>
                <select id="followup_date" name="followup_date">
                    <option value=""><?php esc_html_e('Select a date', 'calendly-bookings'); ?></option>
                </select>
            </label>
            <label for="followup_time"><?php esc_html_e('Follow-up Time', 'calendly-bookings'); ?>
                <select id="followup_time" name="followup_time">
                    <option value=""><?php esc_html_e('Select a time', 'calendly-bookings'); ?></option>
                </select>
            </label>
        </div>
        
        <div class="cb-thickbox-actions">
            <button type="submit" id="cb-walkin-submit" class="button button-primary cb-save-btn"><?php esc_html_e('Save', 'calendly-bookings'); ?></button>
            <button type="button" id="cb-walk-in-cancel" class="button cb-cancel-btn"><?php esc_html_e('Cancel', 'calendly-bookings'); ?></button>
        </div>
    </form>
</div>
