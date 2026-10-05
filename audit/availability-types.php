<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,4).'/wp-load.php';
$api=Calendly_Bookings\Modules\CB_API::instance();
$types=$wpdb->get_results("SELECT id,uuid,uri FROM {$wpdb->prefix}cb_event_types WHERE active=1 ORDER BY id",ARRAY_A);
$details=[];
$hook=function($response,$context,$class,$args,$url)use(&$details){if(str_contains($url,'api.calendly.com/event_type_available_times')){if(!is_wp_error($response)){$b=json_decode(wp_remote_retrieve_body($response),true);$details=['http'=>wp_remote_retrieve_response_code($response),'details'=>$b['details']??null];}}};add_action('http_api_debug',$hook,10,5);
foreach($types as $type){$details=[];$resource=$api->get_event_type($type['uuid']);$entry=['local_id'=>(int)$type['id'],'live_active'=>$resource['resource']['active']??null,'live_type_status'=>$resource['status']??200,'uri_matches'=>($type['uri']==='https://api.calendly.com/event_types/'.$type['uuid'])];try{$slots=$api->query_event_type_available_times($type['uuid']);$entry['slots']=count($slots);$entry['success']=true;}catch(Throwable $error){$entry['success']=false;$entry['message']=$error->getMessage();}$entry=array_merge($entry,$details);echo wp_json_encode($entry,JSON_PRETTY_PRINT).PHP_EOL;}
remove_action('http_api_debug',$hook,10);
