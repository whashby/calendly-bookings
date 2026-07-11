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

  <h2>Report Customization</h2>
  <table class="form-table">
    <tr>
      <th scope="row">File Type</th>
      <td>
        <?php $current = get_option('cb_report_filetype', 'pdf'); ?>
        <select id="cb_report_filetype" name="cb_report_filetype">
          <option value="pdf" <?php selected($current, 'pdf'); ?>>PDF</option>
          <option value="csv" <?php selected($current, 'csv'); ?>>CSV</option>
          <option value="xlsx" <?php selected($current, 'xlsx'); ?>>Excel (XLSX)</option>
        </select>
      </td>
    </tr>
    <tr>
      <th scope="row">Automatic Schedule</th>
      <td>
        <?php $current = get_option('cb_report_schedule', 'none'); ?>
        <select id="cb_report_schedule" name="cb_report_schedule">
          <option value="none" <?php selected($current, 'none'); ?>>None</option>
          <option value="daily" <?php selected($current, 'daily'); ?>>Daily</option>
          <option value="weekly" <?php selected($current, 'weekly'); ?>>Weekly</option>
          <option value="monthly" <?php selected($current, 'monthly'); ?>>Monthly</option>
        </select>
        <input type="time" id="cb_report_time" name="cb_report_time" value="<?php echo esc_attr(get_option('cb_report_time','00:00')); ?>" />
        <input type="date" id="cb_report_day" name="cb_report_day" value="<?php echo esc_attr(get_option('cb_report_day','')); ?>" />
        <p class="description">Choose when scheduled reports should be generated.</p>
      </td>
    </tr>
    <tr>
      <th scope="row">Retention Policy</th>
      <td>
        <input type="number" id="cb_report_retention_count" name="cb_report_retention_count" value="<?php echo esc_attr(get_option('cb_report_retention_count',10)); ?>" min="1" max="100" />
        <label for="cb_report_retention_count">Keep last N reports</label><br>
        <input type="number" id="cb_report_retention_days" name="cb_report_retention_days" value="<?php echo esc_attr(get_option('cb_report_retention_days',30)); ?>" min="1" max="730" />
        <label for="cb_report_retention_days">Keep reports for N days (max 730)</label>
      </td>
    </tr>
  </table>

  <?php submit_button('Save Report Settings'); ?>

  <h3>Manual Report Generation</h3>
  <label for="cb_report_start">Start Date:</label>
  <input type="date" id="cb_report_start" name="cb_report_start" />
  <label for="cb_report_end">End Date:</label>
  <input type="date" id="cb_report_end" name="cb_report_end" />
  <button type="button" class="button" id="cb-generate-report">Generate Report Now</button>
  <button type="button" class="button" id="cb-preview-report">Preview Report</button>

  <h3>Generated Reports</h3>
  <table class="widefat">
    <thead>
      <tr>
        <th>Date Range</th>
        <th>File Type</th>
        <th>Created</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody id="cb-report-list">
      <!-- Populated dynamically with saved reports -->
    </tbody>
  </table>
</form>
<div id="cb-report-preview"></div>
