<?php
declare(strict_types=1);

namespace Calendly_Bookings\Modules;

use Calendly_Bookings\CB_Constants;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Low-memory report engine.
 *
 * Reports are persisted as records rather than a serialized option. Generation
 * happens asynchronously where Action Scheduler is available, and files are
 * written to the WordPress uploads directory instead of memory.
 */
final class CB_Reports {

    public const ACTION = 'cb_generate_report_job';

    private const MAX_PREVIEW_ROWS = 100;
    private const BATCH_SIZE = 100;
    private const RETENTION_COUNT = 50;
    private const RETENTION_DAYS = 90;

    public static function init(): void {
        add_action(self::ACTION, [__CLASS__, 'process_report'], 10, 1);
        add_action('cb_generate_scheduled_report', [__CLASS__, 'generate_scheduled'], 10, 0);
    }


    public static function generate_scheduled(): void {
        $start = gmdate('Y-m-d', strtotime('-1 day'));
        $end = $start;
        $format = sanitize_key((string) get_option(CB_Constants::OPT_REPORT_FILETYPE, 'csv'));
        $fields = (array) get_option('cb_report_fields', []);
        self::create($start, $end, $format, 'sales_general', $fields, 0);
    }

    public static function create(string $start, string $end, string $format, string $type, array $fields, int $user_id = 0): array {
        global $wpdb;

        [$start, $end] = self::validate_dates($start, $end);
        $format = in_array($format, ['csv', 'xlsx', 'pdf'], true) ? $format : 'csv';
        if ($format === 'xlsx' && !class_exists('ZipArchive')) {
            return ['success' => false, 'message' => __('XLSX export requires the PHP Zip extension. Choose CSV or PDF on this server.', 'calendly-bookings')];
        }
        $type = self::allowed_type($type);
        $fields = self::allowed_fields($fields);

        if (!$fields) {
            $fields = self::default_fields($type);
        }

        $uuid = wp_generate_uuid4();
        $table = $wpdb->prefix . CB_Constants::REPORT_TABLE;

        $ok = $wpdb->insert($table, [
            'uuid'       => $uuid,
            'type'       => $type,
            'format'     => $format,
            'status'     => 'queued',
            'start_date' => $start,
            'end_date'   => $end,
            'fields'     => wp_json_encode($fields),
            'created_by' => $user_id ?: get_current_user_id(),
        ], ['%s','%s','%s','%s','%s','%s','%s','%d']);

        if (!$ok) {
            return ['success' => false, 'message' => __('Unable to create report job.', 'calendly-bookings')];
        }

        $id = (int) $wpdb->insert_id;
        self::enqueue($id);

        self::prune();

        return [
            'success' => true,
            'id' => $id,
            'uuid' => $uuid,
            'status' => 'queued',
            'message' => __('Report queued. It will appear in the report history when generation completes.', 'calendly-bookings'),
        ];
    }

    public static function enqueue(int $id): void {
        if ($id <= 0) {
            return;
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION, ['report_id' => $id], 'calendly-bookings');
            return;
        }

        wp_schedule_single_event(time() + 1, self::ACTION, [$id]);
    }

    public static function process_report(int $id): void {
        global $wpdb;

        $table = $wpdb->prefix . CB_Constants::REPORT_TABLE;
        $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", $id), ARRAY_A);
        if (!$report || !in_array($report['status'], ['queued','failed'], true)) {
            return;
        }

        // Prevent concurrent workers from writing the same report.
        $claimed = $wpdb->query($wpdb->prepare("UPDATE {$table} SET status='processing', error_message=NULL WHERE id=%d AND status IN ('queued','failed')", $id));
        if ($claimed !== 1) return;

        $fields = json_decode((string) $report['fields'], true);
        $fields = self::allowed_fields(is_array($fields) ? $fields : []);
        $type = self::allowed_type((string) $report['type']);

        try {
            $dir = self::report_directory();
            if (!wp_mkdir_p($dir['path'])) {
                throw new \RuntimeException(__('Unable to create report directory.', 'calendly-bookings'));
            }

            self::protect_directory($dir['path']);
            $ext = (string) $report['format'];
            $filename = 'report-' . sanitize_key($report['uuid']) . '.' . $ext;
            $path = trailingslashit($dir['path']) . $filename;

            $result = match ($ext) {
                'csv' => self::write_csv($path, $report, $type, $fields),
                'xlsx' => self::write_xlsx($path, $report, $type, $fields),
                'pdf' => self::write_pdf($path, $report, $type, $fields),
                default => throw new \RuntimeException(__('Unsupported report format.', 'calendly-bookings')),
            };

            $wpdb->update($table, [
                'status' => 'completed',
                'row_count' => (int) $result['rows'],
                'file_path' => $path,
                'file_name' => $filename,
                'completed_ts' => current_time('mysql', true),
            ], ['id' => $id], ['%s','%d','%s','%s','%s'], ['%d']);

        } catch (\Throwable $e) {
            $wpdb->update($table, [
                'status' => 'failed',
                'error_message' => sanitize_textarea_field($e->getMessage()),
            ], ['id' => $id], ['%s','%s'], ['%d']);
        }
    }

    public static function preview(string $start, string $end, string $type, array $fields): array {
        [$start, $end] = self::validate_dates($start, $end);
        $type = self::allowed_type($type);
        $fields = self::allowed_fields($fields);
        if (!$fields) {
            $fields = self::default_fields($type);
        }

        $rows = [];
        $orders = self::orders($start, $end, 1, self::MAX_PREVIEW_ROWS, $type);

        if ($type === 'sales_product') {
            $rows = self::aggregate_products($orders);
        } elseif ($type === 'sales_statistics') {
            $rows = self::aggregate_statuses($orders);
        } else {
            $rows[] = array_map([__CLASS__, 'field_label'], $fields);
            foreach ($orders as $order) {
                $rows[] = self::order_row($order, $fields);
            }
        }

        return [
            'html' => self::rows_to_html($rows),
            'summary' => self::summary($orders, $type),
            'rows' => count($rows) > 0 ? count($rows) - 1 : 0,
        ];
    }

    public static function list(int $limit = 25): array {
        global $wpdb;
        $table = $wpdb->prefix . CB_Constants::REPORT_TABLE;
        $limit = max(1, min(100, $limit));

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id,uuid,type,format,status,start_date,end_date,row_count,error_message,created_by,created_ts,completed_ts,file_name FROM {$table} ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        ) ?: [];

        foreach ($rows as &$row) {
            $row['downloadable'] = $row['status'] === 'completed' && !empty($row['file_name']);
            $row['fields'] = [];
        }
        return $rows;
    }

    public static function delete(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . CB_Constants::REPORT_TABLE;
        $row = $wpdb->get_row($wpdb->prepare("SELECT file_path FROM {$table} WHERE id=%d", $id), ARRAY_A);
        if (!$row) {
            return false;
        }

        if (!empty($row['file_path']) && is_file($row['file_path'])) {
            wp_delete_file($row['file_path']);
        }

        return false !== $wpdb->delete($table, ['id' => $id], ['%d']);
    }

    public static function download(int $id): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.', 'calendly-bookings'), 403);
        }

        check_admin_referer('cb_admin_nonce', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . CB_Constants::REPORT_TABLE;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", $id), ARRAY_A);

        if (!$row || $row['status'] !== 'completed' || empty($row['file_path']) || !is_file($row['file_path'])) {
            wp_die(esc_html__('Report is not available.', 'calendly-bookings'), 404);
        }

        $path = realpath($row['file_path']);
        $dir = realpath(self::report_directory()['path']);
        if (!$path || !$dir || !str_starts_with(wp_normalize_path($path), trailingslashit(wp_normalize_path($dir)))) {
            wp_die(esc_html__('Invalid report path.', 'calendly-bookings'), 400);
        }

        while (ob_get_level() > 0) ob_end_clean();
        nocache_headers();
        header('Content-Type: ' . self::mime((string) $row['format']));
        header('Content-Disposition: attachment; filename="' . sanitize_file_name((string) $row['file_name']) . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    public static function bulk_download(array $ids): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.', 'calendly-bookings'), 403);
        }

        $ids = array_values(array_filter(array_map('absint', $ids)));
        if (!$ids || !class_exists('ZipArchive')) {
            wp_die(esc_html__('No reports selected or ZIP support is unavailable.', 'calendly-bookings'), 400);
        }

        global $wpdb;
        $table = $wpdb->prefix . CB_Constants::REPORT_TABLE;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,file_path,file_name,status FROM {$table} WHERE id IN ({$placeholders})",
            $ids
        ), ARRAY_A) ?: [];

        $tmp = self::temporary_file('cb-reports');
        if (!$tmp) {
            wp_die(esc_html__('Unable to create temporary archive.', 'calendly-bookings'), 500);
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            wp_delete_file($tmp);
            wp_die(esc_html__('Unable to create report archive.', 'calendly-bookings'), 500);
        }

        $added = 0;
        foreach ($rows as $row) {
            if ($row['status'] !== 'completed' || empty($row['file_path']) || !is_file($row['file_path'])) {
                continue;
            }
            $real = realpath($row['file_path']);
            $base = realpath(self::report_directory()['path']);
            if (!$real || !$base || !str_starts_with(wp_normalize_path($real), trailingslashit(wp_normalize_path($base)))) continue;
            $zip->addFile($real, sanitize_file_name((string) $row['file_name']));
            $added++;
        }
        $zip->close();

        if ($added === 0) {
            wp_delete_file($tmp);
            wp_die(esc_html__('No completed report files were selected.', 'calendly-bookings'), 404);
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="calendly-booking-reports.zip"');
        header('Content-Length: ' . (string) filesize($tmp));
        readfile($tmp);
        wp_delete_file($tmp);
        exit;
    }

    public static function fields(string $type): array {
        $all = [
            'date' => 'Transaction Date',
            'order_id' => 'Order ID',
            'product' => 'Product(s)',
            'customer' => 'Customer Name',
            'customer_email' => 'Customer Email',
            'transaction_id' => 'Transaction ID',
            'coupon_code' => 'Coupon Code',
            'discount_amount' => 'Discount Amount',
            'refund_amount' => 'Refund Amount',
            'tax' => 'Tax',
            'amount' => 'Order Total',
            'status' => 'Order Status',
            'calendly_status' => 'Calendly Status',
            'meeting_date' => 'Meeting Date',
            'meeting_time' => 'Meeting Time',
            'event_type' => 'Calendly Event Type',
            'invitee_email' => 'Calendly Invitee Email',
        ];

        if ($type === 'sales_product') {
            return [
                'product' => 'Product',
                'units_sold' => 'Units Sold',
                'revenue' => 'Revenue',
                'tax' => 'Tax',
            ];
        }

        if ($type === 'sales_statistics') {
            return [
                'status' => 'Order Status',
                'units' => 'Orders',
                'amount' => 'Revenue',
            ];
        }

        return $all;
    }

    private static function write_csv(string $path, array $report, string $type, array $fields): array {
        $fh = fopen($path, 'wb');
        if (!$fh) {
            throw new \RuntimeException(__('Unable to open report file.', 'calendly-bookings'));
        }

        $rows = 0;
        $orders = self::orders((string) $report['start_date'], (string) $report['end_date'], 1, self::BATCH_SIZE, $type);

        if ($type === 'sales_product') {
            fputcsv($fh, ['Product', 'Units Sold', 'Revenue', 'Tax']);
            $aggregate = [];
            $all_orders = self::iterate_orders($report);
            foreach ($all_orders as $order) {
                foreach ($order->get_items() as $item) {
                    $name = $item->get_name();
                    $aggregate[$name]['units'] = ($aggregate[$name]['units'] ?? 0) + (int) $item->get_quantity();
                    $aggregate[$name]['revenue'] = ($aggregate[$name]['revenue'] ?? 0) + (float) $item->get_total();
                    $aggregate[$name]['tax'] = ($aggregate[$name]['tax'] ?? 0) + (float) $item->get_total_tax();
                }
            }
            foreach ($aggregate as $name => $data) {
                fputcsv($fh, [$name, $data['units'], wc_format_decimal($data['revenue'], wc_get_price_decimals()), wc_format_decimal($data['tax'], wc_get_price_decimals())]);
                $rows++;
            }
        } elseif ($type === 'sales_statistics') {
            fputcsv($fh, ['Order Status', 'Orders', 'Revenue']);
            $aggregate = [];
            foreach (self::iterate_orders($report) as $order) {
                $status = $order->get_status();
                $aggregate[$status]['orders'] = ($aggregate[$status]['orders'] ?? 0) + 1;
                $aggregate[$status]['amount'] = ($aggregate[$status]['amount'] ?? 0) + (float) $order->get_total();
            }
            foreach ($aggregate as $status => $data) {
                fputcsv($fh, [$status, $data['orders'], wc_format_decimal($data['amount'], wc_get_price_decimals())]);
                $rows++;
            }
        } else {
            fputcsv($fh, array_map([__CLASS__, 'field_label'], $fields));
            foreach (self::iterate_orders($report) as $order) {
                fputcsv($fh, self::order_row($order, $fields));
                $rows++;
            }
        }

        fclose($fh);
        return ['rows' => $rows];
    }

    private static function write_xlsx(string $path, array $report, string $type, array $fields): array {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException(__('XLSX generation requires the PHP Zip extension.', 'calendly-bookings'));
        }

        $tmp = self::temporary_file('cb-report-sheet');
        if (!$tmp) {
            throw new \RuntimeException(__('Unable to allocate report workspace.', 'calendly-bookings'));
        }

        $fh = fopen($tmp, 'wb');
        if (!$fh) {
            throw new \RuntimeException(__('Unable to open report workspace.', 'calendly-bookings'));
        }

        fwrite($fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');

        $row_number = 1;
        $rows = 0;
        $write_row = static function ($handle, array $cells, int $row_number): void {
            fwrite($handle, '<row r="' . $row_number . '">');
            foreach (array_values($cells) as $col => $value) {
                $ref = self::column_name($col + 1) . $row_number;
                $value = htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                fwrite($handle, '<c r="' . $ref . '" t="inlineStr"><is><t>' . $value . '</t></is></c>');
            }
            fwrite($handle, '</row>');
        };

        if ($type === 'sales_product') {
            $write_row($fh, ['Product','Units Sold','Revenue','Tax'], $row_number++);
            $aggregate = [];
            foreach (self::iterate_orders($report) as $order) {
                foreach ($order->get_items() as $item) {
                    $name = $item->get_name();
                    $aggregate[$name]['units'] = ($aggregate[$name]['units'] ?? 0) + (int) $item->get_quantity();
                    $aggregate[$name]['revenue'] = ($aggregate[$name]['revenue'] ?? 0) + (float) $item->get_total();
                    $aggregate[$name]['tax'] = ($aggregate[$name]['tax'] ?? 0) + (float) $item->get_total_tax();
                }
            }
            foreach ($aggregate as $name => $data) {
                $write_row($fh, [$name,$data['units'],wc_format_decimal($data['revenue'],wc_get_price_decimals()),wc_format_decimal($data['tax'],wc_get_price_decimals())], $row_number++);
                $rows++;
            }
        } elseif ($type === 'sales_statistics') {
            $write_row($fh, ['Order Status','Orders','Revenue'], $row_number++);
            $aggregate = [];
            foreach (self::iterate_orders($report) as $order) {
                $status = $order->get_status();
                $aggregate[$status]['orders'] = ($aggregate[$status]['orders'] ?? 0) + 1;
                $aggregate[$status]['amount'] = ($aggregate[$status]['amount'] ?? 0) + (float) $order->get_total();
            }
            foreach ($aggregate as $status => $data) {
                $write_row($fh, [$status,$data['orders'],wc_format_decimal($data['amount'], wc_get_price_decimals())], $row_number++);
                $rows++;
            }
        } else {
            $write_row($fh, array_map([__CLASS__, 'field_label'], $fields), $row_number++);
            foreach (self::iterate_orders($report) as $order) {
                $write_row($fh, self::order_row($order, $fields), $row_number++);
                $rows++;
            }
        }

        fwrite($fh, '</sheetData></worksheet>');
        fclose($fh);

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            wp_delete_file($tmp);
            throw new \RuntimeException(__('Unable to create XLSX archive.', 'calendly-bookings'));
        }

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        ];
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->addFile($tmp, 'xl/worksheets/sheet1.xml');
        $zip->close();
        wp_delete_file($tmp);

        return ['rows' => $rows];
    }

    private static function write_pdf(string $path, array $report, string $type, array $fields): array {
        // PDF generation is loaded only for an explicit PDF request. This keeps
        // normal page requests lightweight while preserving a polished export.
        if (class_exists('\\Dompdf\\Dompdf')) {
            $rows = [];
            $rows[] = $type === 'sales_product'
                ? ['Product','Units Sold','Revenue','Tax']
                : ($type === 'sales_statistics' ? ['Order Status','Orders','Revenue'] : array_map([__CLASS__, 'field_label'], $fields));

            $row_count = 0;
            foreach (self::iterate_orders($report) as $order) {
                if ($type === 'sales_product') {
                    // Aggregate below; do not create one row per line item.
                    $rows = self::aggregate_product_rows_for_pdf($report);
                    $row_count = count($rows) - 1;
                    break;
                }
                if ($type === 'sales_statistics') {
                    $rows = self::aggregate_status_rows_for_pdf($report);
                    $row_count = count($rows) - 1;
                    break;
                }

                $rows[] = self::order_row($order, $fields);
                $row_count++;
                if ($row_count >= 5000) break;
            }

            $html = '<!doctype html><html><head><meta charset="utf-8"><style>
                body{font-family:DejaVu Sans,sans-serif;font-size:8px;color:#222}
                h1{font-size:16px;margin:0 0 6px}
                p{margin:0 0 12px;color:#555}
                table{width:100%;border-collapse:collapse}
                th{background:#f1f1f1;font-weight:bold}
                th,td{border:1px solid #ccc;padding:4px;text-align:left;vertical-align:top}
            </style></head><body>';
            $html .= '<h1>Calendly Bookings Report</h1><p>' . esc_html($report['start_date'] . ' to ' . $report['end_date']) . '</p>';
            $html .= '<table><thead><tr>';
            foreach ($rows[0] as $cell) $html .= '<th>' . esc_html((string) $cell) . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach (array_slice($rows, 1) as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) $html .= '<td>' . esc_html((string) $cell) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></body></html>';

            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            file_put_contents($path, $dompdf->output(), LOCK_EX);

            return ['rows' => $row_count];
        }

        // Dependency-free fallback for installations without Dompdf.
        $lines = [
            'Calendly Bookings Report',
            $report['start_date'] . ' to ' . $report['end_date'],
            str_repeat('-', 100),
        ];
        $rows = 0;
        foreach (self::iterate_orders($report) as $order) {
            $lines[] = self::pdf_line(self::order_row($order, $fields));
            $rows++;
            if ($rows >= 5000) break;
        }
        self::make_simple_pdf($path, $lines);
        return ['rows' => $rows];
    }

    private static function aggregate_product_rows_for_pdf(array $report): array {
        $rows = [['Product','Units Sold','Revenue','Tax']];
        $aggregate = [];
        foreach (self::iterate_orders($report) as $order) {
            foreach ($order->get_items() as $item) {
                $name = $item->get_name();
                $aggregate[$name]['units'] = ($aggregate[$name]['units'] ?? 0) + (int) $item->get_quantity();
                $aggregate[$name]['revenue'] = ($aggregate[$name]['revenue'] ?? 0) + (float) $item->get_total();
                $aggregate[$name]['tax'] = ($aggregate[$name]['tax'] ?? 0) + (float) $item->get_total_tax();
            }
        }
        foreach ($aggregate as $name => $data) {
            $rows[] = [$name,$data['units'],wc_format_decimal($data['revenue'], wc_get_price_decimals()),wc_format_decimal($data['tax'], wc_get_price_decimals())];
        }
        return $rows;
    }

    private static function aggregate_status_rows_for_pdf(array $report): array {
        $rows = [['Order Status','Orders','Revenue']];
        $aggregate = [];
        foreach (self::iterate_orders($report) as $order) {
            $status = $order->get_status();
            $aggregate[$status]['orders'] = ($aggregate[$status]['orders'] ?? 0) + 1;
            $aggregate[$status]['amount'] = ($aggregate[$status]['amount'] ?? 0) + (float) $order->get_total();
        }
        foreach ($aggregate as $status => $data) {
            $rows[] = [$status,$data['orders'],wc_format_decimal($data['amount'], wc_get_price_decimals())];
        }
        return $rows;
    }

    private static function iterate_orders(array $report): \Generator {
        $page = 1;
        while (true) {
            $orders = self::orders((string) $report['start_date'], (string) $report['end_date'], $page, self::BATCH_SIZE, (string) $report['type']);
            if (!$orders) {
                break;
            }

            foreach ($orders as $order) {
                yield $order;
            }

            if (count($orders) < self::BATCH_SIZE) {
                break;
            }
            $page++;
        }
    }

    private static function orders(string $start, string $end, int $page, int $limit, string $type): array {
        $args = [
            'status' => ['completed', 'processing', 'refunded', 'cancelled'],
            'date_created' => $start . ' 00:00:00...' . $end . ' 23:59:59',
            'limit' => max(1, min(self::BATCH_SIZE, $limit)),
            'paged' => max(1, $page),
            'return' => 'objects',
            'orderby' => 'date',
            'order' => 'ASC',
        ];

        $orders = (new \WC_Order_Query($args))->get_orders();
        if ($type === 'discounts_refunds') {
            $orders = array_values(array_filter($orders, static function ($order): bool {
                return (float) $order->get_total_discount() > 0 || (float) $order->get_total_refunded() > 0;
            }));
        }

        return $orders;
    }

    private static function order_row(\WC_Order $order, array $fields): array {
        $row = [];
        foreach ($fields as $field) {
            $row[] = self::field_value($order, $field);
        }
        return $row;
    }

    private static function field_value(\WC_Order $order, string $field): string {
        switch ($field) {
            case 'date': return (string) $order->get_date_created()?->date_i18n('Y-m-d H:i:s');
            case 'order_id': return (string) $order->get_order_number();
            case 'product':
                $names = [];
                foreach ($order->get_items() as $item) $names[] = $item->get_name();
                return implode(', ', $names);
            case 'customer': return trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            case 'customer_email': return (string) $order->get_billing_email();
            case 'transaction_id': return (string) $order->get_transaction_id();
            case 'coupon_code': return implode(', ', $order->get_coupon_codes());
            case 'discount_amount': return wc_format_decimal((float) $order->get_total_discount(), wc_get_price_decimals());
            case 'refund_amount': return wc_format_decimal((float) $order->get_total_refunded(), wc_get_price_decimals());
            case 'tax': return wc_format_decimal((float) $order->get_total_tax(), wc_get_price_decimals());
            case 'amount': return wc_format_decimal((float) $order->get_total(), wc_get_price_decimals());
            case 'status': return (string) $order->get_status();
            case 'calendly_status': return (string) $order->get_meta('_cb_calendly_booking_status', true);
            case 'meeting_date':
            case 'meeting_time':
                $iso = (string) $order->get_meta('_cb_meeting_start_iso', true);
                if (!$iso) return '';
                try {
                    $dt = new \DateTimeImmutable($iso);
                    $dt = $dt->setTimezone(wp_timezone());
                    return $field === 'meeting_date'
                        ? wp_date(get_option('date_format'), $dt->getTimestamp(), wp_timezone())
                        : wp_date(get_option('time_format'), $dt->getTimestamp(), wp_timezone());
                } catch (\Throwable $e) {
                    return '';
                }
            case 'event_type':
                return (string) $order->get_meta('_cb_event_uuid', true);
            case 'invitee_email':
                return (string) $order->get_billing_email();
            default: return '';
        }
    }

    private static function aggregate_products(array $orders): array {
        $rows = [['Product','Units Sold','Revenue','Tax']];
        $aggregate = [];
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $name = $item->get_name();
                $aggregate[$name]['units'] = ($aggregate[$name]['units'] ?? 0) + (int) $item->get_quantity();
                $aggregate[$name]['revenue'] = ($aggregate[$name]['revenue'] ?? 0) + (float) $item->get_total();
                $aggregate[$name]['tax'] = ($aggregate[$name]['tax'] ?? 0) + (float) $item->get_total_tax();
            }
        }
        foreach ($aggregate as $name => $data) {
            $rows[] = [$name,$data['units'],wc_format_decimal($data['revenue'], wc_get_price_decimals()),wc_format_decimal($data['tax'], wc_get_price_decimals())];
        }
        return $rows;
    }

    private static function aggregate_statuses(array $orders): array {
        $rows = [['Order Status','Orders','Revenue']];
        $aggregate = [];
        foreach ($orders as $order) {
            $status = $order->get_status();
            $aggregate[$status] = [
                'orders' => ($aggregate[$status]['orders'] ?? 0) + 1,
                'amount' => ($aggregate[$status]['amount'] ?? 0) + (float) $order->get_total(),
            ];
        }
        foreach ($aggregate as $status => $data) {
            $rows[] = [$status,$data['orders'],wc_format_decimal($data['amount'], wc_get_price_decimals())];
        }
        return $rows;
    }

    private static function rows_to_html(array $rows): string {
        if (!$rows) return '<p>' . esc_html__('No data found.', 'calendly-bookings') . '</p>';
        $html = '<table class="widefat striped"><thead><tr>';
        foreach ($rows[0] as $cell) $html .= '<th>' . esc_html((string) $cell) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach (array_slice($rows, 1) as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) $html .= '<td>' . esc_html((string) $cell) . '</td>';
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    private static function summary(array $orders, string $type): string {
        $revenue = 0.0;
        $discounts = 0.0;
        $refunds = 0.0;
        $statuses = [];

        foreach ($orders as $order) {
            $revenue += (float) $order->get_total();
            $discounts += (float) $order->get_total_discount();
            $refunds += (float) $order->get_total_refunded();
            $status = $order->get_status();
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        }

        if ($type === 'discounts_refunds') {
            return sprintf(
                '<strong>Orders:</strong> %d &nbsp; <strong>Discounts:</strong> %s &nbsp; <strong>Refunds:</strong> %s',
                count($orders),
                wp_strip_all_tags(wc_price($discounts)),
                wp_strip_all_tags(wc_price($refunds))
            );
        }

        if ($type === 'sales_statistics') {
            $parts = [];
            foreach ($statuses as $status => $count) {
                $parts[] = esc_html(ucfirst($status)) . ': ' . (int) $count;
            }
            return '<strong>Orders:</strong> ' . count($orders) . ' &nbsp; <strong>Revenue:</strong> ' . wp_strip_all_tags(wc_price($revenue)) . '<br>' . implode(' &nbsp; ', $parts);
        }

        return sprintf(
            '<strong>%s:</strong> %d &nbsp; <strong>%s:</strong> %s',
            esc_html__('Orders', 'calendly-bookings'),
            count($orders),
            esc_html__('Revenue', 'calendly-bookings'),
            wp_strip_all_tags(wc_price($revenue))
        );
    }

    private static function default_fields(string $type): array {
        return match ($type) {
            'sales_product' => ['product','units_sold','revenue','tax'],
            'sales_statistics' => ['status','units','amount'],
            default => ['date','order_id','product','customer','amount','status','calendly_status'],
        };
    }

    private static function allowed_type(string $type): string {
        return in_array($type, ['sales_general','sales_product','discounts_refunds','sales_statistics'], true) ? $type : 'sales_general';
    }

    private static function allowed_fields(array $fields): array {
        $allowed = array_keys(self::fields('sales_general'));
        return array_values(array_unique(array_intersect(array_map('sanitize_key', $fields), $allowed)));
    }

    private static function validate_dates(string $start, string $end): array {
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : gmdate('Y-m-01');
        $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) ? $end : gmdate('Y-m-d');
        if ($start > $end) [$start, $end] = [$end, $start];
        return [$start, $end];
    }

    private static function temporary_file(string $name): string {
        if (!function_exists('wp_tempnam')) require_once ABSPATH . 'wp-admin/includes/file.php';
        return (string) wp_tempnam($name);
    }

    private static function protect_directory(string $path): void {
        $rules = [
            '.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => '<?xml version="1.0"?><configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted="false" /></requestFiltering></security><directoryBrowse enabled="false" /></system.webServer></configuration>',
            'index.php' => "<?php http_response_code(404); exit;",
        ];
        foreach ($rules as $name => $contents) {
            $file = trailingslashit($path) . $name;
            if (!file_exists($file) && file_put_contents($file, $contents, LOCK_EX) === false) throw new \RuntimeException('Unable to protect report directory.');
        }
    }

    private static function report_directory(): array {
        $upload = wp_upload_dir();
        $path = trailingslashit($upload['basedir']) . 'calendly-bookings/reports';
        return ['path' => $path, 'url' => trailingslashit($upload['baseurl']) . 'calendly-bookings/reports'];
    }

    private static function prune(): void {
        global $wpdb;
        $table = $wpdb->prefix . CB_Constants::REPORT_TABLE;
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,file_path FROM {$table} WHERE created_ts < %s ORDER BY id ASC LIMIT 100", $cutoff), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            if (!empty($row['file_path']) && is_file($row['file_path'])) wp_delete_file($row['file_path']);
            $wpdb->delete($table, ['id' => (int) $row['id']], ['%d']);
        }

        $ids = $wpdb->get_col("SELECT id FROM {$table} ORDER BY id DESC LIMIT 18446744073709551615 OFFSET " . self::RETENTION_COUNT);
        foreach ((array) $ids as $id) {
            self::delete((int) $id);
        }
    }

    private static function field_label(string $field): string {
        $labels = self::fields('sales_general');
        return $labels[$field] ?? ucwords(str_replace('_',' ', $field));
    }

    private static function column_name(int $n): string {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }

    private static function mime(string $format): string {
        return match ($format) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
            default => 'text/csv; charset=utf-8',
        };
    }

    private static function pdf_line(array $cells): string {
        return implode(' | ', array_map(static function ($v) {
            $value = preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $v));
            if (function_exists('iconv')) {
                $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
                if ($converted !== false) $value = $converted;
            }
            return mb_substr($value, 0, 90);
        }, $cells));
    }

    private static function make_simple_pdf(string $path, array $lines): void {
        $lines_per_page = 48;
        $pages = array_chunk($lines, $lines_per_page);
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $page_ids = [];
        $next = 3;

        foreach ($pages as $page) {
            $page_ids[] = $next;
            $content_id = $next + 1;
            $stream = "BT /F1 9 Tf 36 806 Td 11 TL\n";
            foreach ($page as $line) {
                $safe = str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], mb_substr($line,0,150));
                $stream .= '(' . $safe . ") Tj T*\n";
            }
            $stream .= "ET";
            $objects[$next] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 ' . ($content_id + 1) . ' 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
            $objects[$content_id] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            $objects[$content_id + 1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
            $next += 3;
        }

        $objects[2] = '<< /Type /Pages /Count ' . count($page_ids) . ' /Kids [' . implode(' 0 R ', $page_ids) . ' 0 R] >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i=1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        file_put_contents($path, $pdf, LOCK_EX);
    }
}
