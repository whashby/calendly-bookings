<?php
$slug = get_post_field('post_name', get_the_ID());

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
      <?php if ($slug !== "hesychia" && in_array($slug, ["initial-consultation","meditation-session","spiritual-companionship"], true)): ?>
        <option value=""><?php esc_html_e('Select a location', 'calendly-bookings'); ?></option>
        <option value="1"><?php esc_html_e('Zoom - Web conferencing details provided upon confirmation.', 'calendly-bookings'); ?></option>
      <?php endif; ?>
      <option value="2"><?php esc_html_e("HIER Life - Skeete's Road Jackmans, St. Michael", 'calendly-bookings'); ?></option>
    </select>
  </div>
</div>

<!-- Date & Time -->
<div class="cb-field-row">
  <div class="cb-field half">
    <label for="cb_meeting_date"><?php esc_html_e('Meeting Date', 'calendly-bookings'); ?></label>
    <input type="text" id="cb_meeting_date" name="cb_meeting_date" placeholder="Select Meeting Date & Time" required />
  </div>
  <div class="cb-field half">
    <label for="cb_meeting_time"><?php esc_html_e('Meeting Time', 'calendly-bookings'); ?></label>
    <div id="cb_meeting_time" class="cb-time-tiles"></div>
    <input type="hidden" name="cb_meeting_time" id="cb_meeting_time_value" required />
  </div>
</div>

<?php
switch ($slug) {
  case "initial-consultation":
    ?>
    <div class="cb-field">
      <label for="cb_hier_intro"><?php esc_html_e('How did you hear about HIER Life?', 'calendly-bookings'); ?></label>
      <select id="cb_hier_intro" name="cb_hier_intro" required>
        <option value=""><?php esc_html_e('Select...', 'calendly-bookings'); ?></option>
        <option value="Google Search"><?php esc_html_e('Google Search', 'calendly-bookings'); ?></option>
        <option value="Word of mouth"><?php esc_html_e('Word of mouth', 'calendly-bookings'); ?></option>
        <option value="Referred by a professional"><?php esc_html_e('Referred by a professional', 'calendly-bookings'); ?></option>
        <option value="Spoke with Michael directly"><?php esc_html_e('Spoke with Michael directly', 'calendly-bookings'); ?></option>
        <option value="Social Media"><?php esc_html_e('Social Media', 'calendly-bookings'); ?></option>
      </select>
    </div>
    <?php
    break;

  case "hesychia":
    ?>
    <div class="cb-field">
      <button type="submit" id="hesychia-submit" class="button cb-submit">
        <?php esc_html_e('Book Hesychia Session', 'calendly-bookings'); ?>
      </button>
    </div>
    <?php
    break;

  case "meditation-session":
    ?>
    <div class="cb-field">
      <label for="cb_prep_notes"><?php esc_html_e('Please share anything that will help prepare for our meeting.', 'calendly-bookings'); ?></label>
      <textarea id="cb_prep_notes" name="cb_prep_notes"></textarea>
    </div>
    <div class="cb-field">
      <label for="cb_new_practice"><?php esc_html_e('Are you new to formal practice of meditation?', 'calendly-bookings'); ?></label>
      <input type="text" id="cb_new_practice" name="cb_new_practice" required>
    </div>
    <div class="cb-field">
      <label for="cb_methods"><?php esc_html_e('If you have practiced before, what methods have you explored? If none respond - N/A', 'calendly-bookings'); ?></label>
      <input type="text" id="cb_methods" name="cb_methods">
    </div>
    <div class="avia_section">
      <label><?php esc_html_e('Do you have any familiarity with the following?', 'calendly-bookings'); ?></label>
      <div class="form_element form_element_checkbox">
        <span class="avia_checkbox"><input type="checkbox" id="cb_centering" name="cb_familiarity[]" value="Centering Prayer"><label for="cb_centering">Centering Prayer</label></span>
        <span class="avia_checkbox"><input type="checkbox" id="cb_contemplation" name="cb_familiarity[]" value="Contemplation"><label for="cb_contemplation">Contemplation</label></span>
        <span class="avia_checkbox"><input type="checkbox" id="cb_mantras" name="cb_familiarity[]" value="Concentrative meditation - mantras"><label for="cb_mantras">Concentrative meditation - mantras</label></span>
        <span class="avia_checkbox"><input type="checkbox" id="cb_lectio" name="cb_familiarity[]" value="Lectio Divina"><label for="cb_lectio">Lectio Divina</label></span>
        <span class="avia_checkbox"><input type="checkbox" id="cb_binaural" name="cb_familiarity[]" value="Binaural beats"><label for="cb_binaural">Binaural beats</label></span>
        <span class="avia_checkbox">
          <input type="checkbox" id="cb_other" name="cb_familiarity[]" value="Other">
          <label for="cb_other">Other</label>
          <input type="text" id="cb_other_text" name="cb_familiarity[]" placeholder="Please specify if other">
        </span>
      </div>
    </div>
    <?php
    break;

  case "spiritual-companionship":
    ?>
    <div class="cb-field">
      <label for="cb_experience"><?php esc_html_e('Since your previous session is there any experience or thought that you would want to raise in the coming session?', 'calendly-bookings'); ?></label>
      <textarea id="cb_experience" name="cb_experience"></textarea>
    </div>
    <?php
    break;

  case "reconnective-healing":
    ?>
    <div class="cb-field">
      <label for="cb_prep_notes"><?php esc_html_e('Please share anything that will help prepare for our meeting.', 'calendly-bookings'); ?></label>
      <textarea id="cb_prep_notes" name="cb_prep_notes"></textarea>
    </div>
    <?php
    break;

  case "qhht-session":
    ?>
    <div class="cb-field">
      <label for="cb_prep_notes"><?php esc_html_e('Please share anything that will help prepare for our meeting.', 'calendly-bookings'); ?></label>
      <textarea id="cb_prep_notes" name="cb_prep_notes"></textarea>
    </div>
    <div class="cb-field">
      <label for="cb_qhht_questions"><?php esc_html_e('Write 6 questions you would want answers for during the session.', 'calendly-bookings'); ?></label>
      <textarea id="cb_qhht_questions" name="cb_qhht_questions"></textarea>
    </div>
    <?php
    break;
}
?>

<p>
  <input type="hidden" name="cb_prefill" value="1">
</p>
