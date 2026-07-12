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
    <div id="cb-sales-general" class="cb-report-tab-panel active">
      <h3>Sales (General) Report Settings</h3>
      <p>Choose fields to include:</p>
      <label><input type="checkbox" class="cb-report-field" value="date" checked> Transaction Date</label><br>
      <label><input type="checkbox" class="cb-report-field" value="product" checked> Product(s)</label><br>
      <label><input type="checkbox" class="cb-report-field" value="customer"> Customer Name</label><br>
      <label><input type="checkbox" class="cb-report-field" value="customer_email"> Customer Email</label><br>
      <label><input type="checkbox" class="cb-report-field" value="transaction_id" checked> Transaction ID</label><br>
      <label><input type="checkbox" class="cb-report-field" value="approval_code" checked> Approval Code</label><br>
      <label><input type="checkbox" class="cb-report-field" value="lgnTransactionId" checked> lgnTransactionId</label><br>
      <label><input type="checkbox" class="cb-report-field" value="is_vct_attempt"> VCT Attempt</label><br>
      <label><input type="checkbox" class="cb-report-field" value="coupon_code" checked> Coupon Code</label><br>
      <label><input type="checkbox" class="cb-report-field" value="discount_amount" checked> Discount Amount</label><br>
      <label><input type="checkbox" class="cb-report-field" value="vat" checked> VAT</label><br>
      <label><input type="checkbox" class="cb-report-field" value="amount" checked> Order Total</label><br>

      <h4>Date Range</h4>
      <label for="cb_report_start">Start Date:</label>
      <input type="date" id="cb_report_start" name="cb_report_start" />
      <label for="cb_report_end">End Date:</label>
      <input type="date" id="cb_report_end" name="cb_report_end" />

      <h4>File Type</h4>
      <select id="cb_report_filetype" name="cb_report_filetype">
        <option value="pdf">PDF</option>
        <option value="csv">CSV</option>
        <option value="xlsx">Excel (XLSX)</option>
      </select>

      <button type="button" class="button" id="cb-preview-report">Preview Report</button>
      <button type="button" class="button" id="cb-generate-report">Generate Report</button>

      <div id="cb-report-preview" style="display:none;">
        <div class="cb-report-actions">
          <button type="button" class="button" id="cb-print-report">Print</button>
          <button type="button" class="button" id="cb-download-preview">Download</button>
        </div>
        <div id="cb-report-preview-content"></div>
        <h3>Summary</h3>
        <div id="cb-report-summary"></div>
      </div>
    </div>
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
    <div id="cb-sales-statistics" class="cb-report-tab-panel">
      <h3>Sales Statistics Report Settings</h3>
      <p>This report shows completed vs cancelled orders.</p>
      <label><input type="checkbox" class="cb-report-field" value="date" checked> Transaction Date</label><br>
      <label><input type="checkbox" class="cb-report-field" value="product" checked> Product(s)</label><br>
      <label><input type="checkbox" class="cb-report-field" value="status" checked> Order Status</label><br>

      <h4>Date Range</h4>
      <input type="date" id="cb_stats_start" name="cb_stats_start" />
      <input type="date" id="cb_stats_end" name="cb_stats_end" />

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

  <h3>Generated Reports</h3>
  <table class="widefat">
    <thead>
      <tr>
        <th>Date Range</th>
        <th>File Type</th>
        <th>Report Type</th>
        <th>Fields Included</th>
        <th>Created</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody id="cb-report-list">
      <!-- Populated dynamically with saved reports -->
    </tbody>
  </table>

  <?php submit_button('Save Report Settings'); ?>
</form>
