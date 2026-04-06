<?php

namespace Calendly_Bookings\Admin\Views\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;
?>
<form method="post" action="options.php">
  <?php settings_fields(CB_Constants::OPT_GROUP); ?>
  <?php do_settings_sections(CB_Constants::OPT_GROUP); ?>

  <h2><?php esc_html_e('Sync Settings', 'calendly-bookings'); ?></h2>

  <table class="form-table">
    <tr>
      <th scope="row"><label for="cb_sync_interval">Sync Interval (minutes)</label></th>
      <td><input type="number" id="cb_sync_interval" name="<?php echo CB_Constants::OPT_SYNC_INTERVAL; ?>" value="<?php echo esc_attr(get_option(CB_Constants::OPT_SYNC_INTERVAL, 5)); ?>" min="5" /></td>
    </tr>
    <tr>
      <th scope="row"><label for="cb_min_start_date">Minimum Start Date</label></th>
      <td><input type="date" id="cb_min_start_date" name="<?php echo CB_Constants::OPT_MIN_START_DATE; ?>" value="<?php echo esc_attr(get_option(CB_Constants::OPT_MIN_START_DATE)); ?>" /></td>
    </tr>
  </table>

  <?php submit_button('Save Sync Settings'); ?>

  <h3><?php esc_html_e('Manual Sync', 'calendly-bookings'); ?></h3>
  <button type="button" class="button" id="cb-sync-all">Run Master Sync</button>
  <button type="button" class="button" id="cb-sync-events">Sync Scheduled Events</button>
  <button type="button" class="button" id="cb-sync-invitees">Sync Event Invitees</button>
  <button type="button" class="button" id="cb-sync-event-types">Sync Event Types</button>
  <button type="button" class="button" id="cb-sync-locations">Sync Locations</button>
</form>
