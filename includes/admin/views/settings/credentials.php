<?php if (!defined('ABSPATH')) exit; ?>

<h2><?php esc_html_e('API Credentials', 'calendly-bookings'); ?></h2>

<!-- Dummy hidden fields to trap browser autofill -->
<input type="text" style="display:none" autocomplete="username" />
<input type="password" style="display:none" autocomplete="new-password" />

<table class="form-table">
  <tr>
    <th scope="row">
      <label for="cb_api_key"><?php esc_html_e('API Key', 'calendly-bookings'); ?></label>
    </th>
    <td>
      <input type="password" id="cb_api_key" name="cb_api_key"
             value="" placeholder="••••••••"
             autocomplete="new-password" class="regular-text" />
      <p class="description"><?php esc_html_e('Enter your Calendly API key.', 'calendly-bookings'); ?></p>
    </td>
  </tr>
  <tr>
    <th scope="row">
      <label for="cb_uuid"><?php esc_html_e('User UUID', 'calendly-bookings'); ?></label>
    </th>
    <td>
      <input type="text" id="cb_uuid" name="cb_uuid"
             value="" placeholder="••••••••"
             autocomplete="off" class="regular-text" />
      <p class="description"><?php esc_html_e('Enter your Calendly user UUID.', 'calendly-bookings'); ?></p>
    </td>
  </tr>
  <tr>
    <th scope="row">
      <label for="cb_license_key"><?php esc_html_e('License Key', 'calendly-bookings'); ?></label>
    </th>
    <td>
      <input type="text" id="cb_license_key" name="cb_license_key"
             value="" autocomplete="off" class="regular-text" />
      <p class="description"><?php esc_html_e('Enter your license key to enable private GitHub updates.', 'calendly-bookings'); ?></p>
    </td>
  </tr>
</table>

<p>
  <button id="cb-test-connection" class="button">
    <?php esc_html_e('Test Connection', 'calendly-bookings'); ?>
  </button>
  <button id="cb-save-settings" class="button button-primary" style="display:none;">
    <?php esc_html_e('Save Settings', 'calendly-bookings'); ?>
  </button>
</p>
