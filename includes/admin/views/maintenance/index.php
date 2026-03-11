<div class="wrap">
  <h1><?php esc_html_e('Calendly Bookings - Maintenance', 'calendly-bookings'); ?></h1>

  <!-- Cache / Product Links -->
  <div class="cb-maintenance-actions">
    <button type="button" class="button cb-maintenance-btn" data-action="clear_cache">
      <?php esc_html_e('Clear API Cache', 'calendly-bookings'); ?>
    </button>
    <button type="button" class="button cb-maintenance-btn" data-action="rebuild_links">
      <?php esc_html_e('Rebuild Product Links', 'calendly-bookings'); ?>
    </button>
  </div>

  <!-- Sync Frequency -->
  <h2><?php esc_html_e('Data sync schedule', 'calendly-bookings'); ?></h2>
  <p><?php esc_html_e('Configure how often Calendly data is synchronized.', 'calendly-bookings'); ?></p>
  <form method="post" action="options.php">
    <?php settings_fields('update_schedule'); ?>
    <?php $current = get_option('calendly_bookings_sync_frequency', 'hourly'); ?>
    <label for="cb-sync-frequency"><?php esc_html_e('Frequency', 'calendly-bookings'); ?></label>
    <select name="calendly_bookings_sync_frequency" id="cb-sync-frequency">
      <option value="5min" <?php selected($current, '5min'); ?>><?php esc_html_e('Every 5 minutes', 'calendly-bookings'); ?></option>
      <option value="15min" <?php selected($current, '15min'); ?>><?php esc_html_e('Every 15 minutes', 'calendly-bookings'); ?></option>
      <option value="30min" <?php selected($current, '30min'); ?>><?php esc_html_e('Every 30 minutes', 'calendly-bookings'); ?></option>
      <option value="hourly" <?php selected($current, 'hourly'); ?>><?php esc_html_e('Hourly', 'calendly-bookings'); ?></option>
      <option value="twicedaily" <?php selected($current, 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'calendly-bookings'); ?></option>
      <option value="daily" <?php selected($current, 'daily'); ?>><?php esc_html_e('Daily', 'calendly-bookings'); ?></option>
    </select>
    <?php submit_button(__('Update schedule', 'calendly-bookings')); ?>
  </form>

  <!-- Payload Maintenance -->
  <h2><?php esc_html_e('Payload maintenance', 'calendly-bookings'); ?></h2>
  <div class="cb-maintenance-actions">
    <button type="button" class="button cb-maintenance-btn" data-action="update_created_ts">
      <?php esc_html_e('Update created_ts from scheduled event payload', 'calendly-bookings'); ?>
    </button>
    <button type="button" class="button cb-maintenance-btn" data-action="refresh_urls">
      <?php esc_html_e('Refresh reschedule/cancel URLs from invitee payloads', 'calendly-bookings'); ?>
    </button>
    <button type="button" class="button cb-maintenance-btn" data-action="backfill_order_ids">
      <?php esc_html_e('Backfill Order IDs from invitee answers', 'calendly-bookings'); ?>
    </button>
    <button type="button" class="button cb-maintenance-btn" data-action="normalize_statuses">
      <?php esc_html_e('Normalize scheduled event statuses from payloads', 'calendly-bookings'); ?>
    </button>
  </div>

  <!-- Sync Progress -->
  <h2><?php esc_html_e('Sync progress', 'calendly-bookings'); ?></h2>
  <p id="cb-sync-status"><?php esc_html_e('Idle', 'calendly-bookings'); ?></p>
</div>
