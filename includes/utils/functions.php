<?php
// includes/utils/functions.php
namespace Calendly_Bookings\Utils;

use Calendly_Bookings\Modules\CB_API;
use Calendly_Bookings\Modules\CB_Audit_Log;
use Calendly_Bookings\CB_Constants;

if (!defined('ABSPATH')) {exit;}

function cb_resolve_timezone(): ?string {
    $tz = wp_timezone_string();
    return $tz ?: null;
}

/**
 * Redirect to checkout immediately for "Initial meeting" product.
 */
add_action('template_redirect', function () {
    if (!isset($_REQUEST['add-to-cart'])) {
        return;
    }

    $product_id = absint($_REQUEST['add-to-cart']);
    $product = wc_get_product($product_id);
    if ($product) {
        $product_name = $product->get_name();

        if ($product_name === "Initial meeting") {
            remove_action('template_redirect', 'wc_template_redirect');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
    }
});

/**
 * Handle meeting-scheduled page logic.
 */
add_action('template_redirect', function() {
    global $wpdb;

    if (is_page('meeting-scheduled')) {
        $start_raw    = isset($_GET['event_start_time']) ? sanitize_text_field($_GET['event_start_time']) : '';
        $order_number = isset($_GET['answer_1']) ? intval($_GET['answer_1']) : 0;

        if (empty($start_raw) || empty($order_number)) {
            if (!current_user_can('manage_options')) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                nocache_headers();
                include get_query_template('404');
                exit;
            }
        }

        $api = new CB_API();
        $api->sync_scheduled_events();

        $event_start = date('Y-m-d H:i:s', strtotime($start_raw));

        if ($event_start && $order_number) {
            $wpdb->update(
                "{$wpdb->prefix}cb_scheduled_events",
                ['order_id' => $order_number],
                ['start_time' => $event_start],
                ['%d'],
                ['%s']
            );
        }
    }
});

/**
 * Force plugin template for meeting-scheduled page.
 */
add_filter('template_include', function($template) {
    $page_id = get_option( \Calendly_Bookings\CB_Installer::get_page_option() );
    
    // If this is the meeting-scheduled page, force plugin template
    if ($page_id && is_page($page_id)) {
        return plugin_dir_path(__FILE__) . '../templates/meeting-scheduled.php';
    }
    
    return $template;
});

/**
 * Schedule cron for monthly revenue report.
 */
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('cb_generate_monthly_report')) {
        wp_schedule_event(time(), 'daily', 'cb_generate_monthly_report');
    }
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('cb_generate_monthly_report');
});

/**
 * Cron callback: generate monthly revenue report.
 */
add_action('cb_generate_monthly_report', function() {
    $last_month = date('Y-m', strtotime('last month'));
    $last_generated = get_option(CB_Constants::OPT_LAST_REPORT_MONTH);

    if (date('j') !== '1' && $last_generated === $last_month) {
        return;
    }

    $last_month_start = date('Y-m-01', strtotime('first day of last month'));
    $last_month_end   = date('Y-m-t', strtotime('last month'));

    $orders = wc_get_orders([
        'limit'        => -1,
        'status'       => 'completed',
        'date_created' => $last_month_start . '...' . $last_month_end,
    ]);

    require_once __DIR__ . '/vendor/autoload.php'; // TCPDF/FPDF
    $pdf = new \TCPDF();
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 12);

    $pdf->Cell(0, 10, "Revenue Report - " . date('F Y', strtotime('last month')), 0, 1, 'C');
    $pdf->Ln(5);

    // Table headers
    $pdf->Cell(40, 10, "Date", 1);
    $pdf->Cell(50, 10, "Customer ID", 1);
    $pdf->Cell(50, 10, "Purchase", 1);
    $pdf->Cell(40, 10, "Payment", 1);
    $pdf->Ln();

    $total_revenue = 0;

    foreach ($orders as $order) {
        $date = $order->get_date_created()->date('Y-m-d');
        $customer_id = sprintf("C-%06d", $order->get_customer_id());
        foreach ($order->get_items() as $item) {
            $purchase = $item->get_name();
            $payment  = $item->get_total();
            $total_revenue += $payment;

            $pdf->Cell(40, 10, $date, 1);
            $pdf->Cell(50, 10, $customer_id, 1);
            $pdf->Cell(50, 10, $purchase, 1);
            $pdf->Cell(40, 10, wc_price($payment), 1);
            $pdf->Ln();
        }
    }

    // Total revenue row
    $pdf->Ln(5);
    $pdf->Cell(140, 10, "Total Revenue", 1);
    $pdf->Cell(40, 10, wc_price($total_revenue), 1);
    $pdf->Ln(15);

    // Summary section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, "Summary", 0, 1);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->MultiCell(0, 8,
        "For " . date('F Y', strtotime('last month')) . ", the total revenue was " . wc_price($total_revenue) .
        ". The report includes " . count($orders) . " completed orders."
    );

    $upload_dir = wp_upload_dir();
    $file = $upload_dir['basedir'] . '/revenue-report.pdf';
    $pdf->Output($file, 'F');

    $subject = "Monthly Revenue Report - " . date('F Y', strtotime('last month'));
    $body    = "Attached is the revenue report for " . date('F Y', strtotime('last month')) . ".";
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    wp_mail('michael@hierlife.com', $subject, $body, $headers, [$file]);

    update_option('cb_last_report_month', $last_month);
});
