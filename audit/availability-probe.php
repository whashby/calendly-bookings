<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 4) . '/wp-load.php';
$type=$wpdb->get_row("SELECT uuid,uri FROM {$wpdb->prefix}cb_event_types WHERE active=1 ORDER BY id LIMIT 1",ARRAY_A);
if(!$type){echo "No active event types.\n";exit(1);}
$days=isset($argv[1])?(int)$argv[1]:30;$start=new DateTimeImmutable('+2 minutes',new DateTimeZone('UTC'));$end=$start->modify('+'.$days.' days');
$url=add_query_arg(['event_type'=>$type['uri']?:'https://api.calendly.com/event_types/'.$type['uuid'],'start_time'=>$start->format('Y-m-d\TH:i:s\Z'),'end_time'=>$end->format('Y-m-d\TH:i:s\Z')],'https://api.calendly.com/event_type_available_times');
$response=wp_remote_get($url,['headers'=>['Authorization'=>'Bearer '.get_option('cb_api_token',''),'Content-Type'=>'application/json'],'timeout'=>20]);
if(is_wp_error($response)){echo wp_json_encode(['transport_error'=>$response->get_error_message()]).PHP_EOL;exit(1);}
$body=json_decode(wp_remote_retrieve_body($response),true);
echo wp_json_encode(['days'=>$days,'status'=>wp_remote_retrieve_response_code($response),'message'=>$body['message']??null,'details'=>$body['details']??null,'slots'=>isset($body['collection'])?count($body['collection']):null],JSON_PRETTY_PRINT).PHP_EOL;
