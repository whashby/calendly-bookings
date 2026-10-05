<?php
use Calendly_Bookings\Modules\CB_Frontend;

global $product;
$product_id = $product instanceof WC_Product ? $product->get_id() : get_the_ID();
$slug = $product instanceof WC_Product ? $product->get_slug() : get_post_field('post_name', $product_id);
$current_user = wp_get_current_user();
$event_type = CB_Frontend::get_event_type_definition($product_id);
$event_uuid = (string) ($event_type['uri'] ?? '');
$event_uuid = $event_uuid ? basename(parse_url($event_uuid, PHP_URL_PATH)) : (string) ($product instanceof WC_Product ? $product->get_meta('_cb_event_uuid', true) : '');

$first_name = $current_user->exists() ? $current_user->user_firstname : '';
$last_name  = $current_user->exists() ? $current_user->user_lastname : '';
$email      = $current_user->exists() ? $current_user->user_email : '';
$locations  = is_array($event_type['locations'] ?? null) ? array_values(array_filter($event_type['locations'], 'is_array')) : [];
$pooling    = strtolower((string) ($event_type['pooling_type'] ?? ''));
$questions  = is_array($event_type['custom_questions'] ?? null) ? $event_type['custom_questions'] : [];
?>
<div class="cb-booking-intro">
  <h3><?php echo CB_Frontend::is_request_product_id((int) $product_id) ? esc_html__('Request a Hesychia Session', 'calendly-bookings') : esc_html__('Book Your Session', 'calendly-bookings'); ?></h3>
  <p><?php echo CB_Frontend::is_request_product_id((int) $product_id) ? esc_html__('Choose a preferred date and time and provide the requested information. Your session request will be processed through the secure checkout.', 'calendly-bookings') : esc_html__('Choose an available date and time, complete the required information, and continue to checkout to book your session.', 'calendly-bookings'); ?></p>
</div>
<div id="cb-calendly-form" class="cb-calendly-form" data-event-uuid="<?php echo esc_attr($event_uuid); ?>" data-event-type-uri="<?php echo esc_attr((string) ($event_type['uri'] ?? "")); ?>">
  <div class="cb-field-row">
    <div class="cb-field half">
      <label for="cb_firstname"><?php esc_html_e('First Name', 'calendly-bookings'); ?></label>
      <input type="text" id="cb_firstname" name="billing_first_name" value="<?php echo esc_attr($first_name); ?>" autocomplete="given-name" required>
    </div>
    <div class="cb-field half">
      <label for="cb_lastname"><?php esc_html_e('Last Name', 'calendly-bookings'); ?></label>
      <input type="text" id="cb_lastname" name="billing_last_name" value="<?php echo esc_attr($last_name); ?>" autocomplete="family-name" required>
    </div>
  </div>
  <div class="cb-field">
    <label for="cb_email"><?php esc_html_e('Email', 'calendly-bookings'); ?></label>
    <input type="email" id="cb_email" name="billing_email" value="<?php echo esc_attr($email); ?>" autocomplete="email" required>
  </div>

  <?php if ($pooling !== 'round_robin' && $locations): ?>
    <div class="cb-field">
      <label for="cb_meeting_location"><?php esc_html_e('Location', 'calendly-bookings'); ?></label>
      <?php if (count($locations) === 1 && strtolower((string) ($locations[0]['kind'] ?? '')) !== 'ask_invitee'): ?>
        <?php $loc = $locations[0]; $key = CB_Frontend::location_key($loc, 0); ?>
        <input type="hidden" name="cb_meeting_location" id="cb_meeting_location" value="<?php echo esc_attr($key); ?>">
        <div class="cb-location-summary"><strong><?php echo esc_html(ucwords(str_replace('_', ' ', (string) ($loc['kind'] ?? '')))); ?></strong><?php if (!empty($loc['location'])): ?> — <?php echo esc_html($loc['location']); ?><?php endif; ?></div>
      <?php else: ?>
        <select id="cb_meeting_location" name="cb_meeting_location" required>
          <option value=""><?php esc_html_e('Select a location', 'calendly-bookings'); ?></option>
          <?php foreach ($locations as $i => $loc): $key = CB_Frontend::location_key($loc, $i); $kind = strtolower((string) ($loc['kind'] ?? '')); ?>
            <option value="<?php echo esc_attr($key); ?>" data-location-index="<?php echo esc_attr($i); ?>"><?php echo esc_html(ucwords(str_replace('_', ' ', $kind))); ?><?php if (!empty($loc['location'])): ?> — <?php echo esc_html($loc['location']); ?><?php endif; ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <input type="hidden" name="cb_meeting_location_details" id="cb_meeting_location_details" value="">
      <?php $requires_location_detail = false; foreach ($locations as $loc_check) { if (in_array(strtolower((string)($loc_check['kind'] ?? '')), ['ask_invitee','outbound_call'], true)) { $requires_location_detail = true; break; } } ?>
      <?php if ($requires_location_detail): ?>
        <label for="cb_meeting_location_detail_text"><?php esc_html_e('Location details', 'calendly-bookings'); ?></label>
        <input type="text" id="cb_meeting_location_detail_text" name="cb_meeting_location_detail_text" autocomplete="street-address" required>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="cb-field-row">
    <div class="cb-field half">
      <label for="cb_meeting_date"><?php esc_html_e('Meeting Date', 'calendly-bookings'); ?></label>
      <input type="text" id="cb_meeting_date" name="cb_meeting_date" placeholder="Select Meeting Date" required readonly>
    </div>
    <div class="cb-field half">
      <label for="cb_meeting_time"><?php esc_html_e('Meeting Time', 'calendly-bookings'); ?></label>
      <div id="cb_meeting_time" class="cb-time-tiles" aria-live="polite"></div>
      <input type="hidden" name="cb_meeting_time" id="cb_meeting_time_value" required>
      <input type="hidden" name="cb_meeting_start_iso" id="cb_meeting_start_iso" required>
    </div>
  </div>

  <?php foreach ($questions as $position => $question):
      if (!is_array($question) || empty($question['enabled'])) continue;
      $name = trim((string) ($question['name'] ?? ''));
      if ($name === '' || preg_match('/^order\s*id(?:\b|\s*\()/i', $name)) continue;
      $type = strtolower((string) ($question['type'] ?? 'string'));
      $key = CB_Frontend::question_key($question);
      $required = !empty($question['required']);
      $choices = is_array($question['answer_choices'] ?? null) ? $question['answer_choices'] : [];
      $field_name = 'cb_calendly_answers[' . $key . ']';
      ?>
      <div class="cb-field cb-calendly-question" data-question-key="<?php echo esc_attr($key); ?>" data-question="<?php echo esc_attr($name); ?>" data-position="<?php echo esc_attr(absint($question['position'] ?? $position)); ?>">
        <label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($name); ?><?php if ($required): ?> <span aria-hidden="true">*</span><?php endif; ?></label>
        <?php if ($type === 'single_select'): ?>
          <select id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($field_name); ?>" <?php echo $required ? 'required' : ''; ?>>
            <option value=""><?php esc_html_e('Select...', 'calendly-bookings'); ?></option>
            <?php foreach ($choices as $choice): ?><option value="<?php echo esc_attr($choice); ?>"><?php echo esc_html($choice); ?></option><?php endforeach; ?>
            <?php if (!empty($question['include_other'])): ?><option value="Other"><?php esc_html_e('Other', 'calendly-bookings'); ?></option><?php endif; ?>
          </select>
        <?php elseif ($type === 'multi_select'): ?>
          <div class="cb-choice-list">
            <?php foreach ($choices as $i => $choice): ?><label><input type="checkbox" name="<?php echo esc_attr($field_name); ?>[]" value="<?php echo esc_attr($choice); ?>"> <?php echo esc_html($choice); ?></label><?php endforeach; ?>
          </div>
        <?php elseif ($type === 'text'): ?>
          <textarea id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($field_name); ?>" rows="4" <?php echo $required ? 'required' : ''; ?>></textarea>
        <?php elseif ($type === 'phone_number'): ?>
          <input type="tel" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($field_name); ?>" autocomplete="tel" <?php echo $required ? 'required' : ''; ?>>
        <?php elseif ($type === 'date'): ?>
          <input type="date" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($field_name); ?>" <?php echo $required ? 'required' : ''; ?>>
        <?php else: ?>
          <input type="text" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($field_name); ?>" <?php echo $required ? 'required' : ''; ?>>
        <?php endif; ?>
        <?php if (!empty($question['include_other']) && $type === 'multi_select'): ?>
          <input type="text" name="cb_calendly_answers_other[<?php echo esc_attr($key); ?>]" class="cb-other-answer" placeholder="<?php esc_attr_e('Please specify if other', 'calendly-bookings'); ?>">
        <?php endif; ?>
      </div>
  <?php endforeach; ?>

  <input type="hidden" name="cb_prefill" value="1">
  <input type="hidden" name="cb_event_uuid" value="<?php echo esc_attr($event_uuid); ?>">
  <input type="hidden" name="cb_event_type_uri" value="<?php echo esc_attr((string) ($event_type['uri'] ?? '')); ?>">
  <input type="hidden" name="cb_booking_product_id" value="<?php echo esc_attr($product_id); ?>">
  <input type="hidden" name="add-to-cart" value="<?php echo esc_attr($product_id); ?>">
  <?php wp_nonce_field('cb_add_meeting_' . $product_id, 'cb_booking_nonce', false, true); ?>
</div>
