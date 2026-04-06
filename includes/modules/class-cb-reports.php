<?php 

namespace Calendly_Bookings\Modules;

use Calendly_Bookings\CB_Constants;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class CB_Reports {
    public static function init(): void {
        add_action('cb_generate_report_event', [__CLASS__, 'generate_report']);
    }

    public static function schedule_report(): void {
        $cron_expr = get_option('cb_report_schedule');
        if ($cron_expr) {
            if (!wp_next_scheduled('cb_generate_report_event')) {
                wp_schedule_event(time(), 'daily', 'cb_generate_report_event');
            }
        }
    }

    public static function generate_report(): string {
        $template = get_option('cb_report_template', 'Default Report Template');
        $filetype = get_option('cb_report_filetype', 'pdf');

        $content = "<h1>Calendly Bookings Report</h1><p>{$template}</p>";

        switch ($filetype) {
            case 'csv':
                return self::to_csv($content);
            case 'xlsx':
                return self::to_xlsx($content);
            case 'pdf':
            default:
                return self::to_pdf($content);
        }
    }

    private static function to_pdf(string $content): string {
        // Using Dompdf for PDF generation
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($content);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output(); // raw PDF binary
    }

    private static function to_csv(string $content): string {
        $rows = [
            ['Report Title', 'Content'],
            ['Calendly Bookings Report', strip_tags($content)]
        ];
        $csv = '';
        foreach ($rows as $row) {
            $csv .= '"' . implode('","', $row) . '"' . "\n";
        }
        return $csv;
    }

    private static function to_xlsx(string $content): string {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Report Title');
        $sheet->setCellValue('B1', 'Content');
        $sheet->setCellValue('A2', 'Calendly Bookings Report');
        $sheet->setCellValue('B2', strip_tags($content));

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        return ob_get_clean(); // raw XLSX binary
    }
}
