<?php
namespace Calendly_Bookings\Admin\Views\Settings;

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
  <h1><?php esc_html_e('Calendly Bookings Settings', 'calendly-bookings'); ?></h1>

  <div id="cb-admin-notices"></div>

  <form id="cb-settings-form" method="post" autocomplete="off">
    <?php wp_nonce_field('cb_settings_save', 'cb_settings_nonce'); ?>

    <!-- Dummy hidden fields to trap browser autofill -->
    <input type="text" style="display:none" autocomplete="username" />
    <input type="password" style="display:none" autocomplete="new-password" />

    <!-- Credentials Section -->
    <?php echo \Calendly_Bookings\Modules\CB_Admin::view('settings/credentials'); ?>

    <!-- Actions Section -->
    <?php echo \Calendly_Bookings\Modules\CB_Admin::view('settings/actions'); ?>
  </form>
</div>
