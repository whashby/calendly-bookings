<?php

namespace Calendly_Bookings\Admin\Views\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;
?>
<div class="cb-sync-settings">

  <!-- Master Sync -->
  <div class="cb-sync-controls">
    <h2>Master Sync</h2>
    <label class="cb-switch">
      <input type="checkbox" id="cb_master_sync" name="cb_master_sync" value="1"
        <?php checked(get_option('cb_master_sync'), 1); ?> />
      <span class="cb-slider"></span>
    </label>
    <?php
    $schedules = wp_get_schedules();
    $current   = get_option('cb_master_frequency', 'cb_daily');
    ?>
    <select id="cb_master_frequency" name="cb_master_frequency">
      <?php foreach ($schedules as $key => $schedule): ?>
        <?php if (strpos($key, 'cb_') !== 0) continue; ?>
        <option value="<?php echo esc_attr($key); ?>" <?php selected($current, $key); ?>>
          <?php echo esc_html($schedule['display']); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <span class="cb-status-badge <?php echo get_option('cb_master_sync') ? 'enabled' : 'disabled'; ?>">
      <?php echo get_option('cb_master_sync') ? 'Enabled' : 'Disabled'; ?>
    </span>
  </div>

  <!-- Individual Syncs -->
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
          <input type="checkbox"
            class="cb-individual-sync"
            id="cb_sync_<?php echo $key; ?>"
            name="cb_sync_<?php echo $key; ?>"
            value="1"
            <?php checked($enabled, 1); ?>
            <?php disabled(get_option('cb_master_sync')); ?> />
          <span class="cb-slider"></span>
        </label>
        <span><?php echo esc_html($label); ?></span>
        <select
          id="cb_sync_<?php echo $key; ?>_frequency"
          name="cb_sync_<?php echo $key; ?>_frequency"
          class="cb-individual-frequency"
          <?php disabled(get_option('cb_master_sync')); ?>>
          <?php foreach ($schedules as $sched_key => $schedule): ?>
            <?php if (strpos($sched_key, 'cb_') !== 0) continue; ?>
            <option value="<?php echo esc_attr($sched_key); ?>" <?php selected($freq, $sched_key); ?>>
              <?php echo esc_html($schedule['display']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="cb-status-badge <?php echo $enabled ? 'enabled' : 'disabled'; ?>">
          <?php echo $enabled ? 'Enabled' : 'Disabled'; ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Active Cron Jobs -->
  <div class="cb-sync-crons">
    <h2>Active Cron Jobs</h2>
    <ul id="cb-cron-list">
      <?php
      $crons = _get_cron_array();
      foreach ($crons as $timestamp => $jobs) {
        foreach ($jobs as $hook => $details) {
          if (strpos($hook, 'cb_') === 0) {
            echo "<li>{$hook} → next run: " . date('Y-m-d H:i:s', $timestamp) . "</li>";
          }
        }
      }
      ?>
    </ul>
  </div>

</div>
