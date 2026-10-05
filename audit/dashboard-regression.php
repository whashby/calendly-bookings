<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 4) . '/wp-load.php';
use Calendly_Bookings\Modules\CB_Dashboard_REST as Dashboard;
use Calendly_Bookings\Modules\CB_Sync_Status as Sync;
$passed=0;
function verify($ok,$label){global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$label);$passed++;echo 'PASS: '.$label.PHP_EOL;}
$filters=[];
function option_fixture($key,$value){global $filters;$fn=fn()=> $value;add_filter('pre_option_'.$key,$fn);$filters[]=['pre_option_'.$key,$fn];}
try{
 option_fixture('timezone_string','America/Barbados');option_fixture('cb_api_token','fixture-token');
 foreach(['master','scheduled_events','invitees','event_types','locations','availability'] as $domain)option_fixture('cb_sync_result_'.$domain,[]);
 foreach(['cb_last_sync_all','cb_last_sync_scheduled_events','cb_last_sync_scheduled_event_invitees','cb_last_sync_event_types','cb_last_sync_locations','cb_last_sync_event_type_available_times'] as $key)option_fixture($key,0);
 $stamp=strtotime('2026-10-04T21:30:00Z');option_fixture('cb_sync_result_scheduled_events',['last_success'=>$stamp,'last_attempt'=>$stamp,'success'=>true,'errors'=>[]]);
 $next=time()+300;option_fixture('cron',[$next=>['cb_sync_scheduled_events_cron'=>[md5(serialize([]))=>['schedule'=>'cb_every_5_minutes','args'=>[],'interval'=>300]]],'version'=>2]);
 $health=Dashboard::get_sync_health();$jobs=Sync::schedules();
 verify($health['last_sync']==='2026-10-04T21:30:00Z','Health uses background sync success timestamp in UTC');
 verify(wp_date('g:i a',$stamp,wp_timezone())==='5:30 pm','Afternoon sync renders as PM in site timezone');
 verify($health['schedules']===$jobs,'Dashboard and Settings use identical schedule data');
 verify($jobs['scheduled_events']['enabled']&&$jobs['scheduled_events']['frequency']==='cb_every_5_minutes'&&$jobs['scheduled_events']['next_run']===$next,'Five-minute background schedule and next run are accurate');
 verify(!$jobs['master']['enabled'],'Disabled master schedule is reported as disabled');
 verify($health['calendly_api']==='Last sync succeeded','Health reflects recorded result rather than constant OK');
 option_fixture('cb_sync_result_master',['last_success'=>$stamp,'last_attempt'=>$stamp+60,'success'=>false,'errors'=>['Fixture failure']]);
 $failed=Dashboard::get_sync_health();verify($failed['calendly_api']==='Sync errors recorded'&&isset($failed['errors']['master']),'Failed sync is visible');
 verify($failed['last_sync']===$health['last_sync'],'Failed sync does not replace last successful timestamp');
 $key='cb_sync_result_audit_fixture';$previous=get_option($key,null);
 try{Sync::record('audit_fixture',['success'=>true,'errors'=>[]]);$good=Sync::state('audit_fixture');Sync::record('audit_fixture',['success'=>false,'errors'=>['Fixture failure']]);$bad=Sync::state('audit_fixture');verify($bad['last_success']===$good['last_success']&&!$bad['success'],'Shared recorder preserves success across failures');}finally{if($previous===null)delete_option($key);else update_option($key,$previous,false);}
 $item=new class { function get_product_id(){return 1;} function get_total(){return '80';} function get_meta($key,$single){return $key==='_cb_booking_event_uuid'?'historical-event':'';} };
 $order=new class($item){private $item;function __construct($item){$this->item=$item;}function get_items(){return [10=>$this->item];}function get_total_refunded_for_item($id){return -15;}};
 $net=new ReflectionMethod(Dashboard::class,'order_net_sales');
 verify($net->invoke(null,$order,['uuid'=>'historical-event','product_id'=>999])===65.0,'Net sales use discounted order total less refund, even after product relinking');
 verify($net->invoke(null,$order,['uuid'=>'different-event','product_id'=>1])===0.0,'Stored booking identity prevents sales attributed to unrelated event');
 $real=$wpdb;
 try{
 $wpdb=new class($real){public $prefix;private $db;function __construct($db){$this->db=$db;$this->prefix=$db->prefix;}function prepare($query,...$args){return $this->db->prepare($query,...$args);}function get_results($query,$type){return [['name'=>'Fixture','next_slot'=>'2026-10-05 14:15:00']];}function get_col($query){return ['2026-10-04 02:30:00','2026-10-04 04:30:00'];}};
 verify(Dashboard::get_availability_snapshot()[0]['slots'][0]==='2026-10-05T14:15:00Z','Available slot preserves exact quarter-hour UTC time');
 $request=new WP_REST_Request();$request->set_param('months',1);$trends=Dashboard::get_booking_trends($request);
 verify($trends===[['day'=>'2026-10-03','count'=>1],['day'=>'2026-10-04','count'=>1]],'Booking trends group by site date across UTC midnight');
 }finally{$wpdb=$real;}
}finally{foreach(array_reverse($filters) as [$name,$fn])remove_filter($name,$fn);}
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);if($admins)wp_set_current_user((int)$admins[0]);$server=rest_get_server();
foreach(['health','availability','integrity','trends','performance','recent-bookings','revenue'] as $endpoint){$request=new WP_REST_Request('GET','/calendly-bookings/v1/dashboard/'.$endpoint);$request->set_param('months',1);$response=$server->dispatch($request);verify($response->get_status()===200&&$wpdb->last_error==='','Local '.$endpoint.' widget endpoint returns 200 without query errors');}
$request=new WP_REST_Request('GET','/calendly-bookings/v1/dashboard/sync');verify($server->dispatch($request)->get_status()===404,'GET cannot trigger a dashboard sync mutation');
$health=Dashboard::get_sync_health();verify($health['schedules']===Sync::schedules(),'Live dashboard schedules match live Settings source');
$request=new WP_REST_Request();$request->set_param('months',1);$revenue=Dashboard::get_revenue_tracker($request);$performance=Dashboard::get_event_type_performance($request);verify(abs($revenue['total_revenue']-array_sum(array_column($performance,'revenue')))<0.01,'Revenue total matches event performance totals');
echo $passed.' dashboard checks passed.'.PHP_EOL;
