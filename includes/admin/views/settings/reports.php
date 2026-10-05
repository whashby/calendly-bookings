<?php
namespace Calendly_Bookings\Admin\Views\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\Modules\CB_Reports;

$default_fields = CB_Reports::fields('sales_general');
$saved_fields = (array) get_option('cb_report_fields', ['date','order_id','product','customer','amount','status','calendly_status']);
$default_format = (string) get_option('cb_report_filetype', 'csv');
if ($default_format === 'xlsx' && !class_exists('ZipArchive')) $default_format = 'csv';
$start = (string) get_option('cb_report_start', gmdate('Y-m-01'));
$end = (string) get_option('cb_report_end', gmdate('Y-m-d'));
?>
<div class="cb-settings-card">
    <div class="cb-settings-card__header">
        <div>
            <h2><?php esc_html_e('Reports', 'calendly-bookings'); ?></h2>
            <p><?php esc_html_e('Generate operational, sales, and booking reports without loading the entire order history into the browser.', 'calendly-bookings'); ?></p>
        </div>
        <span class="cb-status-badge enabled"><?php esc_html_e('Asynchronous', 'calendly-bookings'); ?></span>
    </div>

    <div class="cb-report-controls">
        <p>
            <label for="cb_report_type"><?php esc_html_e('Report type', 'calendly-bookings'); ?></label>
            <select id="cb_report_type">
                <option value="sales_general"><?php esc_html_e('Sales — General', 'calendly-bookings'); ?></option>
                <option value="sales_product"><?php esc_html_e('Sales — By Product', 'calendly-bookings'); ?></option>
                <option value="discounts_refunds"><?php esc_html_e('Discounts / Refunds', 'calendly-bookings'); ?></option>
                <option value="sales_statistics"><?php esc_html_e('Sales Statistics', 'calendly-bookings'); ?></option>
            </select>
        </p>
        <p><label for="cb_report_start"><?php esc_html_e('Start date', 'calendly-bookings'); ?></label>
            <input type="date" id="cb_report_start" value="<?php echo esc_attr($start); ?>">
        </p>
        <p><label for="cb_report_end"><?php esc_html_e('End date', 'calendly-bookings'); ?></label>
            <input type="date" id="cb_report_end" value="<?php echo esc_attr($end); ?>">
        </p>
        <p><label for="cb_report_filetype"><?php esc_html_e('Format', 'calendly-bookings'); ?></label>
            <select id="cb_report_filetype">
                <option value="csv" <?php selected($default_format, 'csv'); ?>>CSV</option>
                <option value="xlsx" <?php selected($default_format, 'xlsx'); ?> <?php disabled(!class_exists('ZipArchive')); ?>>Excel (XLSX)<?php echo class_exists('ZipArchive') ? '' : ' — PHP Zip extension required'; ?></option>
                <option value="pdf" <?php selected($default_format, 'pdf'); ?>>PDF</option>
            </select>
        </p>
    </div>

    <div class="cb-report-fields" data-field-map="<?php echo esc_attr(wp_json_encode([
        'sales_general' => CB_Reports::fields('sales_general'),
        'sales_product' => CB_Reports::fields('sales_product'),
        'discounts_refunds' => CB_Reports::fields('sales_general'),
        'sales_statistics' => CB_Reports::fields('sales_statistics'),
    ])); ?>">
        <h3><?php esc_html_e('Fields', 'calendly-bookings'); ?></h3>
        <div class="cb-report-field-grid">
            <?php foreach ($default_fields as $key => $label): ?>
                <label>
                    <input type="checkbox" class="cb-report-field" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $saved_fields, true)); ?>>
                    <?php echo esc_html($label); ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="cb-settings-actions">
        <button type="button" class="button" id="cb-preview-report"><?php esc_html_e('Preview', 'calendly-bookings'); ?></button>
        <button type="button" class="button button-primary" id="cb-generate-report"><?php esc_html_e('Generate report', 'calendly-bookings'); ?></button>
        <button type="button" class="button" id="cb-save-report-settings"><?php esc_html_e('Save defaults', 'calendly-bookings'); ?></button>
        <span id="cb-report-status" class="cb-inline-status" aria-live="polite"></span>
    </div>

    <div id="cb-report-preview-panel" class="cb-report-preview-panel" hidden>
        <div class="cb-report-preview-toolbar">
            <strong><?php esc_html_e('Preview', 'calendly-bookings'); ?></strong>
            <span id="cb-report-summary"></span>
        </div>
        <div id="cb-report-preview-content"></div>
    </div>

    <hr>

    <div class="cb-report-history-header">
        <div>
            <h3><?php esc_html_e('Generated reports', 'calendly-bookings'); ?></h3>
            <p class="description"><?php esc_html_e('Completed files are stored outside the database and are available only to administrators.', 'calendly-bookings'); ?></p>
        </div>
        <button type="button" class="button" id="cb-refresh-reports"><?php esc_html_e('Refresh', 'calendly-bookings'); ?></button>
    </div>

    <div class="cb-report-history-wrap">
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Type', 'calendly-bookings'); ?></th>
                    <th><?php esc_html_e('Range', 'calendly-bookings'); ?></th>
                    <th><?php esc_html_e('Format', 'calendly-bookings'); ?></th>
                    <th><?php esc_html_e('Status', 'calendly-bookings'); ?></th>
                    <th><?php esc_html_e('Rows', 'calendly-bookings'); ?></th>
                    <th><?php esc_html_e('Created', 'calendly-bookings'); ?></th>
                    <th><?php esc_html_e('Actions', 'calendly-bookings'); ?></th>
                </tr>
            </thead>
            <tbody id="cb-report-list">
                <tr><td colspan="7"><?php esc_html_e('Loading reports…', 'calendly-bookings'); ?></td></tr>
            </tbody>
        </table>
    </div>
</div>
