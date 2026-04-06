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
      <th scope="row"><label for="cb_report_template">Report Template</label></th>
      <td><textarea id="cb_report_template" name="cb_report_template" rows="6" cols="60"><?php echo esc_textarea(get_option('cb_report_template')); ?></textarea></td>
    </tr>
    <tr>
      <th scope="row"><label for="cb_report_filetype">File Type</label></th>
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
      <th scope="row"><label for="cb_report_schedule">Schedule</label></th>
      <td>
        <?php $current = get_option('cb_report_schedule', 'daily'); ?>
        <select id="cb_report_schedule" name="cb_report_schedule">
          <option value="none" <?php selected($current, 'none'); ?>>None</option>
          <option value="hourly" <?php selected($current, 'hourly'); ?>>Hourly</option>
          <option value="twicedaily" <?php selected($current, 'twicedaily'); ?>>Twice Daily</option>
          <option value="daily" <?php selected($current, 'daily'); ?>>Daily</option>
          <option value="weekly" <?php selected($current, 'weekly'); ?>>Weekly</option>
        </select>
      </td>
    </tr>
  </table>

  <?php submit_button('Save Report Settings'); ?>

  <h3>Manual Report Generation</h3>
  <button type="button" class="button" id="cb-generate-report">Generate Report Now</button>
  <button type="button" class="button" id="cb-preview-report">Preview Report</button>
</form>
<div id="cb-report-preview"></div>
