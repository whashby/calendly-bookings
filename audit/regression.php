<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 4) . '/wp-load.php';
use Calendly_Bookings\Modules\CB_API;
use Calendly_Bookings\Modules\CB_Webhooks;
use Calendly_Bookings\Modules\CB_Reports;
$passed = 0;
function check($value, $label) { global $passed; if (!$value) throw new RuntimeException('FAIL: ' . $label); $passed++; echo 'PASS: ' . $label . PHP_EOL; }
$token = 'cb-audit-fixture-token';
$api = new CB_API($token, 'fixture-user');
$mode = 'pages'; $calls = [];
$mock = function($pre, $args, $url) use (&$mode, &$calls) {
 if (!str_starts_with($url, 'https://api.calendly.com/')) return $pre;
 $calls[] = $url; parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
 $code = $mode === 'unauthorized' ? 401 : ($mode === 'throttle' ? 429 : 200);
 $body = ['collection' => [], 'pagination' => ['next_page_token' => null]];
 if ($mode === 'pages') {
   $n = empty($q['page_token']) ? 100 : 1;
   $body['collection'] = array_fill(0, $n, ['uri' => 'fixture']);
   $body['pagination']['next_page_token'] = empty($q['page_token']) ? 'page2' : null;
 }
 if ($mode === 'unauthorized' || $mode === 'throttle') $body = ['message' => 'Fixture API failure'];
 return ['headers' => ['retry-after' => '120'], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => 'fixture'], 'cookies' => []];
};
add_filter('pre_http_request', $mock, 10, 3);
try {
 check(count($api->query_event_types()) === 101 && count($calls) === 2, 'Event types follow all pages');
 $calls=[]; check(count($api->query_scheduled_events(200))===101, 'Scheduled event total limit does not become invalid page size');
 foreach($calls as $url) { parse_str(parse_url($url,PHP_URL_QUERY),$q); check((int)$q['count']<=100, 'Calendly page size is at most 100'); }
 $calls=[]; check(count($api->query_scheduled_event_invitees('fixture-event'))===101,'Invitees follow all pages');
 check(!str_contains($calls[0], 'user='), 'Invitee endpoint omits unsupported user parameter');
 $mode='empty'; check($api->query_event_types()===[], 'Empty collection is valid');
 $mode='unauthorized'; $failed=false; try {$api->query_scheduled_events();} catch(RuntimeException $e) {$failed=str_contains($e->getMessage(),'401');}
 check($failed,'HTTP authentication failures are not reported as empty success');
 $mode='throttle'; try {$api->query_event_types();} catch(RuntimeException $e) {}
 check($api->retry_after()>=119,'429 preserves Retry-After');
 $before=count($calls); $res=$api->create_invitee([]);
 check(($res['status']??0)===429 && count($calls)===$before,'Rate-limit cooldown prevents another network request');
 delete_transient('cb_api_rate_' . md5($token));
 $mode='empty'; $res=$api->get_event_type_availability('https://api.calendly.com/event_types/fixture','2000-01-01');
 parse_str(parse_url(end($calls),PHP_URL_QUERY),$q);
 check(strtotime($q['start_time'])>time() && strtotime($q['end_time'])-strtotime($q['start_time'])<=31*DAY_IN_SECONDS,'Availability is future UTC within the current 31-day limit');
 delete_transient('cb_api_' . md5($token . '|' . end($calls)));
} finally { remove_filter('pre_http_request',$mock,10); delete_transient('cb_api_rate_' . md5($token)); }
$secret='fixture-signing-key';$body=wp_json_encode(['event'=>'fixture.ignored','payload'=>[]]);$ts=time();$header='t='.$ts.',v1='.hash_hmac('sha256',$ts.'.'.$body,$secret);
$signature=new ReflectionMethod(CB_Webhooks::class,'verify_signature');
check($signature->invoke(null,$body,$header,$secret),'Valid webhook signature accepted');
check(!$signature->invoke(null,$body.'x',$header,$secret),'Tampered webhook rejected');
$old=$ts-600;$oldHeader='t='.$old.',v1='.hash_hmac('sha256',$old.'.'.$body,$secret);
check(!$signature->invoke(null,$body,$oldHeader,$secret),'Expired webhook signature rejected');
$secretFilter=fn()=> $secret; add_filter('pre_option_cb_webhook_secret',$secretFilter);
$request=new WP_REST_Request('POST');$request->set_header('Calendly-Webhook-Signature',$header);$request->set_body($body);
$response=CB_Webhooks::receive($request); remove_filter('pre_option_cb_webhook_secret',$secretFilter);
check($response->get_status()===200 && !empty($response->get_data()['ignored']),'WordPress-normalized signature header is read correctly');
$report=$wpdb->get_row('SELECT * FROM '.$wpdb->prefix.'cb_reports ORDER BY id DESC LIMIT 1',ARRAY_A);
if ($report) {
 foreach(['csv','xlsx','pdf'] as $format) {
  if($format==='xlsx'&&!class_exists('ZipArchive')) continue;
  $method=new ReflectionMethod(CB_Reports::class,'write_'.$format);$path=__DIR__.'/validation.'.$format;
  $result=$method->invoke(null,$path,$report,$report['type'],json_decode($report['fields'],true));
  check(is_file($path)&&filesize($path)>0,'Existing report filters export to '.strtoupper($format));
  if($format==='pdf')check(file_get_contents($path,false,null,0,5)==='%PDF-','PDF has valid file signature');
  if($format==='xlsx'){$z=new ZipArchive();check($z->open($path)===true&&$z->locateName('xl/worksheets/sheet1.xml')!==false,'XLSX contains worksheet');$z->close();}
 }
}
echo $passed . ' checks passed.' . PHP_EOL;
