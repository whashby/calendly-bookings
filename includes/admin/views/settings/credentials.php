<?php

namespace Calendly_Bookings\Admin\Views\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;
?>
<form method="post" action="options.php" autocomplete="off">
  <!-- Dummy hidden fields to trap browser autofill -->
  <input type="text" style="display:none" autocomplete="username" />
  <input type="password" style="display:none" autocomplete="new-password" />

  <?php settings_fields('calendly_bookings_credentials'); ?>
  <?php do_settings_sections('calendly_bookings_credentials'); ?>
  <table class="form-table">
    <tr>
      <th scope="row"><label for="cb_api_key">API Key</label></th>
      <td><input type="password" id="cb_api_key" name="cb_api_key" value="" placeholder="••••••••" autocomplete="off" /></td>
    </tr>
    <tr>
      <th scope="row"><label for="cb_user_uuid">User UUID</label></th>
      <td><input type="text" id="cb_user_uuid" name="cb_user_uuid" value="" placeholder="••••••••" autocomplete="off" /></td>
    </tr>
    <tr>
      <th scope="row"><label for="cb_license_key">License Key</label></th>
      <td><input type="text" id="cb_license_key" name="cb_license_key" value="" placeholder="••••••••" autocomplete="off" /></td>
    </tr>
  </table>

  <?php submit_button('Save Credentials'); ?>
  <button type="button" class="button" id="cb-test-connection">Test Connection</button>
</form>
