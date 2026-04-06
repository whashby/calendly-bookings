<?php
namespace Calendly_Bookings\Admin\Views\Settings;

if (!defined('ABSPATH')) {
    exit;
}


add_action('wp_ajax_cb_test_connection', function() {
  $api_key = sanitize_text_field($_POST['api_key']);
  $uuid    = sanitize_text_field($_POST['user_uuid']);
  $license = sanitize_text_field($_POST['license_key']);

  // Load existing options if fields are empty
  $stored_api = get_option('cb_api_key');
  $stored_uuid = get_option('cb_user_uuid');

  $test_api = $api_key ?: $stored_api;
  $test_uuid = $uuid ?: $stored_uuid;

  // Connection test logic
  $success = cb_test_calendly_connection($test_api, $test_uuid);

  // License validation
  $license_valid = cb_validate_license($license);

  wp_send_json([
    'success' => $success && $license_valid,
    'message' => $success
      ? ($license_valid ? 'Connection and license valid.' : 'Connection OK, license invalid.')
      : 'Connection failed.'
  ]);
});
