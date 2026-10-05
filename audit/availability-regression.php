<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,4).'/wp-load.php';
use Calendly_Bookings\Modules\CB_API;
$api=new CB_API('cb-availability-fixture','fixture-user');$calls=[];$invalid=false;$passed=0;
function verify($ok,$label){global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$label);$passed++;echo 'PASS: '.$label.PHP_EOL;}
$mock=function($pre,$args,$url)use(&$calls,&$invalid){if(!str_starts_with($url,'https://api.calendly.com/'))return $pre;$calls[]=$url;return ['headers'=>[],'body'=>wp_json_encode($invalid?['message'=>'The supplied parameters are invalid.','details'=>[['parameter'=>'start_time','message'=>'start_time must be in the future']]]:['collection'=>[]]),'response'=>['code'=>$invalid?400:200,'message'=>'fixture'],'cookies'=>[]];};
add_filter('pre_http_request',$mock,10,3);
try{
 foreach(['2000-01-01',gmdate('c',time()+86400)] as $start){$before=time();$result=$api->get_event_type_availability('https://api.calendly.com/event_types/fixture',$start);parse_str(parse_url(end($calls),PHP_URL_QUERY),$q);verify(strtotime($q['start_time']) >= $before+120,'Frontend availability has at least two minutes lead time');verify(strtotime($q['end_time'])-strtotime($q['start_time'])===30*DAY_IN_SECONDS,'Availability window is 30 days');verify(str_ends_with($q['start_time'],'Z') && str_ends_with($q['end_time'],'Z'),'Availability uses UTC timestamps');if(strtotime($start)>$before+120)verify(strtotime($q['start_time'])===strtotime($start),'Requested future start is retained');}
 $before=time();$api->query_event_type_available_times('12345678-1234-1234-1234-123456789abc');parse_str(parse_url(end($calls),PHP_URL_QUERY),$q);verify(strtotime($q['start_time']) >= $before+120,'Background availability uses the same safe lead time');verify(!isset($q['user']),'Availability omits unsupported user parameter');
 $invalid=true;$result=$api->get_event_type_availability('https://api.calendly.com/event_types/error-fixture',gmdate('c'));verify(!empty($result['error']) && str_contains($result['message'],'start_time must be in the future'),'API errors retain parameter-specific details');
 $failed=false;try{$api->query_event_type_available_times('../invalid');}catch(InvalidArgumentException $e){$failed=true;}verify($failed,'Invalid event type UUID is rejected before a request');
}finally{remove_filter('pre_http_request',$mock,10);foreach($calls as $url)delete_transient('cb_api_'.md5('cb-availability-fixture|'.$url));}
echo $passed.' checks passed'.PHP_EOL;
