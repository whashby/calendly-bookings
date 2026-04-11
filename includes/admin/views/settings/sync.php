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
    <?php
    // Get all schedules and filter for Calendly Bookings only
    $schedules = wp_get_schedules();
    $current   = get_option('cb_master_frequency', 'cb_daily');

    echo '<select id="cb_master_frequency" name="cb_master_frequency">';
    foreach ($schedules as $key => $schedule) {
        if (strpos($key, 'cb_') !== 0) continue; // only Calendly schedules
        echo '<option value="' . esc_attr($key) . '" ' . selected($current, $key, false) . '>';
        echo esc_html($schedule['display']);
        echo '</option>';
    }
    echo '</select>';
    ?>
  </div>

  <h2>Individual Syncs</h2>
  <div id="cb-individual-section">
    <?php
    $syncs = [
      'events'      => 'Scheduled Events',
      'invitees'    => 'Event Invitees',
      'event_types' => 'Event Types',
      'locations'   => 'Locations'
    ];
    foreach ($syncs as $key => $label): 
      $enabled = get_option("cb_sync_{$key}", 0);
      $freq    = get_option("cb_sync_{$key}_frequency", 'cb_daily');
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
        <select id="<?php echo "cb_sync_{$key}_frequency"; ?>" name="<?php echo "cb_sync_{$key}_frequency"; ?>"
          <?php disabled(get_option('cb_master_sync')); ?>>
          <?php
          foreach ($schedules as $sched_key => $schedule) {
            if (strpos($sched_key, 'cb_') !== 0) continue; // only Calendly schedules
            echo '<option value="' . esc_attr($sched_key) . '" ' . selected($freq, $sched_key, false) . '>';
            echo esc_html($schedule['display']);
            echo '</option>';
          }
          ?>
        </select>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cb-sync-crons">
    <h2>Active Cron Jobs</h2>
    <ul id="cb-cron-list">
      <?php
      $crons = _get_cron_array();
      foreach ($crons as $timestamp => $jobs) {
        foreach ($jobs as $hook => $details) {
          // Only show Calendly Bookings jobs
          if (strpos($hook, 'cb_') === 0) {
            echo "<li>{$hook} → next run: " . date('Y-m-d H:i:s', $timestamp) . "</li>";
          }
        }
      }
      ?>
    </ul>
  </div>
</div>
