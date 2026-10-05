<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 4) . '/wp-load.php';
$r=$wpdb->get_row('SELECT * FROM ' . $wpdb->prefix . 'cb_reports ORDER BY id DESC LIMIT 1', ARRAY_A);
if (!$r) exit;
try {
 $m=new ReflectionMethod(\Calendly_Bookings\Modules\CB_Reports::class, 'write_pdf');
 $m->invoke(null, __DIR__ . '/report-validation.pdf', $r, $r['type'], json_decode($r['fields'],true));
 echo "PDF generation passed\n";
} catch (Throwable $e) { echo $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"; }
