<?php

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Register settings for sync interval.
 */
function cb_register_settings() {
    add_settings_section(
        'cb_sync_section',
        __('Data Sync', 'calendly-bookings'),
        '__return_false',
        'calendly-bookings-settings'
    );

    add_settings_field(
        'cb_sync_interval',
        __('Sync Frequency', 'calendly-bookings'),
        'cb_sync_interval_field',
        'calendly-bookings-settings',
        'cb_sync_section'
    );

    register_setting('calendly-bookings-settings', 'cb_sync_interval');
}
add_action('admin_init', 'cb_register_settings');

/**
 * Render sync interval dropdown.
 */
function cb_sync_interval_field() {
    $value = get_option('cb_sync_interval', 'hourly');
    ?>
    <select name="cb_sync_interval" id="cb_sync_interval" autocomplete="off">
        <option value="hourly" <?php selected($value, 'hourly'); ?>>
            <?php esc_html_e('Hourly', 'calendly-bookings'); ?>
        </option>
        <option value="twicedaily" <?php selected($value, 'twicedaily'); ?>>
            <?php esc_html_e('Twice Daily', 'calendly-bookings'); ?>
        </option>
        <option value="daily" <?php selected($value, 'daily'); ?>>
            <?php esc_html_e('Daily', 'calendly-bookings'); ?>
        </option>
    </select>
    <?php
}
?>

<!-- Sync Actions UI -->
<h2><?php esc_html_e('Data Sync', 'calendly-bookings'); ?></h2>
<p><?php esc_html_e('Choose how often Calendly data should be refreshed automatically.', 'calendly-bookings'); ?></p>

<p>
  <button id="cb-run-sync" class="button button-primary">
    <?php esc_html_e('Run Sync Now', 'calendly-bookings'); ?>
  </button>
</p>

<div id="cb-sync-result"></div>

<?php
// Display last sync status if available
$last_sync = get_option('cb_last_sync', []);
if (!empty($last_sync) && !empty($last_sync['time'])) {
    $time  = esc_html($last_sync['time']);
    $count = isset($last_sync['events']) ? count((array) $last_sync['events']) : 0;
    ?>
    <p class="description">
      <?php
      printf(
        esc_html__('Last sync: %s (%d events)', 'calendly-bookings'),
        $time,
        $count
      );
      ?>
    </p>
    <?php
}
?>
