<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 4) . '/wp-load.php';
use Calendly_Bookings\Modules\CB_Reports;
$r=$wpdb->get_row("SELECT * FROM {$wpdb->prefix}cb_reports ORDER BY id DESC LIMIT 1", ARRAY_A);
if (!$r) exit;
if (in_array($r['status'], ['queued','failed'],true)) CB_Reports::process_report((int)$r['id']);
$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cb_reports WHERE id=%d",$r['id']), ARRAY_A);
if (in_array('--download',$argv,true)) {
 $users=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
 if(!$users)exit(1);wp_set_current_user((int)$users[0]);
 $_REQUEST['nonce']=wp_create_nonce('cb_admin_nonce');
 CB_Reports::download((int)$r['id']);
}
echo wp_json_encode(['status'=>$r['status'],'rows'=>(int)$r['row_count'],'file_present'=>!empty($r['file_path'])&&is_file($r['file_path']),'error'=>$r['error_message']],JSON_PRETTY_PRINT).PHP_EOL;
