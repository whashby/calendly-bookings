<?php

namespace Calendly_Bookings\Admin\Views\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;
?>
<div class="cb-sync-settings">
  <div class="cb-sync-controls">
    <h2>Master Sync</h2>
    <label class="cb-switch">
      <input type="checkbox" id="cb_master_sync" name="cb_master_sync" value="1"
        <?php checked(get_option('cb_master_sync'), 1); ?> />
      <span class="cb-slider"></span>
    </label>
    <select id="cb_master_frequency" name="cb_master_frequency"
      <?php disabled(!get_option('cb_master_sync')); ?>>
      <?php $freq = get_option('cb_master_frequency', 'daily'); ?>
      <option value="hourly" <?php selected($freq, 'hourly'); ?>>Hourly</option>
      <option value="twicedaily" <?php selected($freq, 'twicedaily'); ?>>Twice Daily</option>
      <option value="daily" <?php selected($freq, 'daily'); ?>>Daily</option>
      <option value="weekly" <?php selected($freq, 'weekly'); ?>>Weekly</option>
    </select>

    <h2>Individual Syncs</h2>
    <div id="cb-individual-section">
      <?php
      $syncs = [
        'events'    => 'Scheduled Events',
        'invitees'  => 'Event Invitees',
        'event_types' => 'Event Types',
        'locations' => 'Locations'
      ];
      foreach ($syncs as $key => $label): 
        $enabled = get_option("cb_sync_{$key}", 0);
        $freq    = get_option("cb_sync_{$key}_frequency", 'daily');
      ?>
        <div class="cb-sync-item">
          <label class="cb-switch">
            <input type="checkbox" class="cb-individual-sync" id="cb_sync_<?php echo $key; ?>"
              name="cb_sync_<?php echo $key; ?>" value="1"
              <?php checked($enabled, 1); ?>
              <?php disabled(get_option('cb_master_sync')); ?> />
            <span class="cb-slider"></span>
          </label>
          <span><?php echo esc_html($label); ?></span>
          <select id="cb_sync_<?php echo $key; ?>_frequency" name="cb_sync_<?php echo $key; ?>_frequency"
            <?php disabled(!$enabled || get_option('cb_master_sync')); ?>>
            <option value="hourly" <?php selected($freq, 'hourly'); ?>>Hourly</option>
            <option value="twicedaily" <?php selected($freq, 'twicedaily'); ?>>Twice Daily</option>
            <option value="daily" <?php selected($freq, 'daily'); ?>>Daily</option>
            <option value="weekly" <?php selected($freq, 'weekly'); ?>>Weekly</option>
          </select>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="cb-sync-crons">
    <h2>Active Cron Jobs</h2>
    <ul id="cb-cron-list">
      <?php
      $crons = _get_cron_array();
      foreach ($crons as $timestamp => $jobs) {
        foreach ($jobs as $hook => $details) {
          if (strpos($hook, 'cb_run_') === 0) {
            echo "<li>{$hook} → next run: " . date('Y-m-d H:i:s', $timestamp) . "</li>";
          }
        }
      }
      ?>
    </ul>
  </div>
</div>
