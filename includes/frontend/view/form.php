<?php
$current_user = wp_get_current_user();
$first_name   = esc_attr($current_user->user_firstname ?? '');
$last_name    = esc_attr($current_user->user_lastname ?? '');
$email        = esc_attr($current_user->user_email ?? '');
?>

  <div class="cb-field-row">
    <div class="cb-field half">
      <label for="cb_firstname"><?php esc_html_e('First Name', 'calendly-bookings'); ?></label>
      <input type="text" id="cb_firstname" name="billing_first_name" value="<?php echo $first_name; ?>" required>
    </div>

    <div class="cb-field half">
      <label for="cb_lastname"><?php esc_html_e('Last Name', 'calendly-bookings'); ?></label>
      <input type="text" id="cb_lastname" name="billing_last_name" value="<?php echo $last_name; ?>" required>
    </div>
  </div>

  <div class="cb-field">
    <label for="cb_email"><?php esc_html_e('Email', 'calendly-bookings'); ?></label>
    <input type="email" id="cb_email" name="billing_email" value="<?php echo $email; ?>" required>
  </div>

  <!-- Location -->
  <div class="cb-field-row">
    <div class="cb-field">
      <label for="cb_meeting_location"><?php esc_html_e('Location', 'calendly-bookings'); ?></label>
      <select id="cb_meeting_location" name="cb_meeting_location" required>
        <option value=""><?php esc_html_e('Select a location', 'calendly-bookings'); ?></option>
        <option value="1">
          <?php esc_html_e('Zoom - Web conferencing details provided upon confirmation.', 'calendly-bookings'); ?>
        </option>
        <option value="2">
          <?php esc_html_e("HIER Life - Skeete's Road Jackmans, St. Michael", 'calendly-bookings'); ?>
        </option>
      </select>
    </div>
  </div>

  <!-- Date & Time -->
  <div class="cb-field-row">
    <div class="cb-field half">
      <label for="cb_meeting_date"><?php esc_html_e('Meeting Date', 'calendly-bookings'); ?></label>
      <select id="cb_meeting_date" name="cb_meeting_date" required>
        <option value=""><?php esc_html_e('Select a date', 'calendly-bookings'); ?></option>
      </select>
    </div>

    <div class="cb-field half">
      <label for="cb_meeting_time"><?php esc_html_e('Meeting Time', 'calendly-bookings'); ?></label>
      <select id="cb_meeting_time" name="cb_meeting_time" required>
        <option value=""><?php esc_html_e('Select a time', 'calendly-bookings'); ?></option>
      </select>
    </div>
  </div>

  <!-- Intro -->
  <div class="cb-field">
    <label for="cb_hier_intro"><?php esc_html_e('How did you hear about HIER Life?', 'calendly-bookings'); ?></label>
    <select id="cb_hier_intro" name="cb_hier_intro" required>
      <option value=""><?php esc_html_e('Select...', 'calendly-bookings'); ?></option>
      <option value="<?php esc_html_e('Google Search', 'calendly-bookings'); ?>"><?php esc_html_e('Google Search', 'calendly-bookings'); ?></option>
      <option value="<?php esc_html_e('Word of mouth', 'calendly-bookings'); ?>"><?php esc_html_e('Word of mouth', 'calendly-bookings'); ?></option>
      <option value="<?php esc_html_e('Referred by a professional', 'calendly-bookings'); ?>"><?php esc_html_e('Referred by a professional', 'calendly-bookings'); ?></option>
      <option value="<?php esc_html_e('Spoke with Michael directly', 'calendly-bookings'); ?>"><?php esc_html_e('Spoke with Michael directly', 'calendly-bookings'); ?></option>
      <option value="<?php esc_html_e('Social Media', 'calendly-bookings'); ?>"><?php esc_html_e('Social Media', 'calendly-bookings'); ?></option>
    </select>
  </div>

  <p>
    <input type="hidden" name="cb_prefill" value="1">
  </p>
