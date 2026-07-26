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

  <h2>Reports</h2>
  <p>Select a report type below. Each tab has its own settings and field options.</p>

  <div id="cb-report-tabs" class="nav-tab-wrapper">
    <a href="#cb-sales-general" class="nav-tab nav-tab-active">Sales (General)</a>
    <a href="#cb-sales-product" class="nav-tab">Sales by Product</a>
    <a href="#cb-discounts-refunds" class="nav-tab">Discounts / Refunds</a>
    <a href="#cb-sales-statistics" class="nav-tab">Sales Statistics</a>
  </div>

  <div class="cb-report-tab-content">
    <!-- Sales General -->
    <div id="cb-sales-general" class="cb-report-tab-panel active">
      <h3>Sales (General) Report Settings</h3>
      <p>Choose fields to include:</p>
      <?php $saved_fields = get_option('cb_report_fields', []); ?>
      <label><input type="checkbox" class="cb-report-field" value="date" <?php checked(in_array('date',(array)$saved_fields)); ?>> Transaction Date</label><br>
      <label><input type="checkbox" class="cb-report-field" value="product" <?php checked(in_array('product',(array)$saved_fields)); ?>> Product(s)</label><br>
      <label><input type="checkbox" class="cb-report-field" value="customer" <?php checked(in_array('customer',(array)$saved_fields)); ?>> Customer Name</label><br>
      <label><input type="checkbox" class="cb-report-field" value="customer_email" <?php checked(in_array('customer_email',(array)$saved_fields)); ?>> Customer Email</label><br>
      <label><input type="checkbox" class="cb-report-field" value="transaction_id" <?php checked(in_array('transaction_id',(array)$saved_fields)); ?>> Transaction ID</label><br>
      <label><input type="checkbox" class="cb-report-field" value="approval_code" <?php checked(in_array('approval_code',(array)$saved_fields)); ?>> Approval Code</label><br>
      <label><input type="checkbox" class="cb-report-field" value="coupon_code" <?php checked(in_array('coupon_code',(array)$saved_fields)); ?>> Coupon Code</label><br>
      <label><input type="checkbox" class="cb-report-field" value="discount_amount" <?php checked(in_array('discount_amount',(array)$saved_fields)); ?>> Discount Amount</label><br>
      <label><input type="checkbox" class="cb-report-field" value="vat" <?php checked(in_array('vat',(array)$saved_fields)); ?>> VAT</label><br>
      <label><input type="checkbox" class="cb-report-field" value="amount" <?php checked(in_array('amount',(array)$saved_fields)); ?>> Order Total</label><br>

      <h4>Date Range</h4>
      <input type="date" id="cb_report_start" name="cb_report_start" value="<?php echo esc_attr(get_option('cb_report_start','')); ?>" />
      <input type="date" id="cb_report_end" name="cb_report_end" value="<?php echo esc_attr(get_option('cb_report_end','')); ?>" />

      <h4>File Type</h4>
      <?php $filetype = get_option('cb_report_filetype','pdf'); ?>
      <select id="cb_report_filetype" name="cb_report_filetype">
        <option value="pdf" <?php selected($filetype,'pdf'); ?>>PDF</option>
        <option value="csv" <?php selected($filetype,'csv'); ?>>CSV</option>
        <option value="xlsx" <?php selected($filetype,'xlsx'); ?>>Excel (XLSX)</option>
      </select>

      <button type="button" class="button" id="cb-preview-report">Preview Report</button>
      <button type="button" class="button" id="cb-generate-report">Generate Report</button>

      <div id="cb-report-preview" style="display:none;">
        <div class="cb-report-actions">
          <button type="button" class="button" id="cb-print-report">Print</button>
          <button type="button" class="button" id="cb-download-preview">Download</button>
        </div>
        <div id="cb-report-preview-content"></div>
      </div>
    </div>

    <!-- Sales by Product -->
    <div id="cb-sales-product" class="cb-report-tab-panel">
      <h3>Sales by Product Report Settings</h3>
      <p>This report groups sales by product.</p>
      <label><input type="checkbox" class="cb-report-field" value="product" checked> Product</label><br>
      <label><input type="checkbox" class="cb-report-field" value="units_sold" checked> Units Sold</label><br>
      <label><input type="checkbox" class="cb-report-field" value="revenue" checked> Revenue</label><br>
      <label><input type="checkbox" class="cb-report-field" value="vat"> VAT</label><br>

      <h4>Date Range</h4>
      <input type="date" id="cb_product_start" name="cb_product_start" />
      <input type="date" id="cb_product_end" name="cb_product_end" />

      <button type="button" class="button" id="cb-preview-product-report">Preview Report</button>
      <button type="button" class="button" id="cb-generate-product-report">Generate Report</button>

      <div id="cb-product-report-preview" style="display:none;">
        <div class="cb-report-actions">
          <button type="button" class="button" id="cb-print-product-report">Print</button>
          <button type="button" class="button" id="cb-download-product-preview">Download</button>
        </div>
        <div id="cb-product-report-preview-content"></div>
        <h3>Summary</h3>
        <div id="cb-product-report-summary"></div>
      </div>
    </div>

    <!-- Discounts / Refunds -->
    <div id="cb-discounts-refunds" class="cb-report-tab-panel">
      <h3>Discounts / Refunds Report Settings</h3>
      <p>This report lists coupons and refunds.</p>
      <label><input type="checkbox" class="cb-report-field" value="coupon_code" checked> Coupon Code</label><br>
      <label><input type="checkbox" class="cb-report-field" value="discount_amount" checked> Discount Amount</label><br>
      <label><input type="checkbox" class="cb-report-field" value="refund_amount"> Refund Amount</label><br>

      <h4>Date Range</h4>
      <input type="date" id="cb_discount_start" name="cb_discount_start" />
      <input type="date" id="cb_discount_end" name="cb_discount_end" />

      <button type="button" class="button" id="cb-preview-discount-report">Preview Report</button>
      <button type="button" class="button" id="cb-generate-discount-report">Generate Report</button>

      <div id="cb-discount-report-preview" style="display:none;">
        <div class="cb-report-actions">
          <button type="button" class="button" id="cb-print-discount-report">Print</button>
          <button type="button" class="button" id="cb-download-discount-preview">Download</button>
        </div>
        <div id="cb-discount-report-preview-content"></div>
        <h3>Summary</h3>
        <div id="cb-discount-report-summary"></div>
      </div>
    </div>

    <!-- Sales Statistics -->
    <div id="cb-sales-statistics" class="cb-report-tab-panel">
      <h3>Sales Statistics Report Settings</h3>
      <p>This report shows completed vs cancelled orders.</p>
      <label><input type="checkbox" class="cb-report-field" value="date" checked> Transaction Date</label><br>
      <label><input type="checkbox" class="cb-report-field" value="product" checked> Product(s)</label><br>
      <label><input type="checkbox" class="cb-report-field" value="status" checked> Order Status</label><br>

      <h4>Date Range</h4>
      <input type="date" id="cb_stats_start" name="cb_stats_start" value="<?php echo esc_attr(get_option('cb_stats_start','')); ?>" />
      <input type="date" id="cb_stats_end" name="cb_stats_end" value="<?php echo esc_attr(get_option('cb_stats_end','')); ?>" />

      <button type="button" class="button" id="cb-preview-stats-report">Preview Report</button>
      <button type="button" class="button" id="cb-generate-stats-report">Generate Report</button>

      <div id="cb-stats-report-preview" style="display:none;">
        <div class="cb-report-actions">
          <button type="button" class="button" id="cb-print-stats-report">Print</button>
          <button type="button" class="button" id="cb-download-stats-preview">Download</button>
        </div>
        <div id="cb-stats-report-preview-content"></div>
        <h3>Summary</h3>
        <div id="cb-stats-report-summary"></div>
      </div>
    </div>
  </div> <!-- end cb-report-tab-content -->
<?php submit_button('Save Report Settings', 'primary', 'cb-save-settings'); ?>

  <h3>Generated Reports</h3>
  <table class="widefat">
    <thead>
      <tr>
        <th><input type="checkbox" id="cb-select-all-reports"></th>
        <th>Date Range</th>
        <th>File Type</th>
        <th>Report Type</th>
        <th>Fields Included</th>
        <th>Created</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody id="cb-report-list">
      <?php
      $reports = get_option('cb_generated_reports', []);
      if (empty($reports)) {
          echo '<tr><td colspan="7">No reports available</td></tr>';
      } else {
          foreach ($reports as $report) {
              echo '<tr>
                  <td><input type="checkbox" class="cb-report-select" data-id="' . esc_attr($report['id']) . '"></td>
                  <td>' . esc_html($report['date_range']) . '</td>
                  <td>' . esc_html(strtoupper($report['file_type'])) . '</td>
                  <td>' . esc_html(str_replace('_',' ', $report['type'])) . '</td>
                  <td>' . esc_html(implode(', ', $report['fields'])) . '</td>
                  <td>' . esc_html(date('Y-m-d H:i:s', $report['created'])) . '</td>
                  <td>
                    <a href="' . esc_url($report['download_url']) . '" class="button">Download</a>
                    <button type="button" class="button cb-delete-report" data-id="' . esc_attr($report['id']) . '">Delete</button>
                  </td>
              </tr>';
          }
      }
      ?>
    </tbody>
  </table>

  <div class="cb-report-bulk-actions">
    <button type="button" class="button" id="cb-delete-selected">Delete Selected</button>
    <button type="button" class="button" id="cb-download-selected">Download Selected</button>
  </div>

</form>
