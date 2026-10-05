<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}require dirname(__DIR__,4).'/wp-load.php';
$uuid=$wpdb->get_var("SELECT uuid FROM {$wpdb->prefix}cb_event_types WHERE active=1 ORDER BY id LIMIT 1");
add_action('http_api_debug',function($response,$context,$class,$args,$url){if(str_contains($url,'api.calendly.com/event_type_available_times')){parse_str((string)parse_url($url,PHP_URL_QUERY),$query);echo wp_json_encode(['local_utc'=>gmdate('c'),'start_time'=>$query['start_time']??null,'lead_seconds'=>strtotime($query['start_time']??'')-time(),'response_date'=>is_wp_error($response)?null:wp_remote_retrieve_header($response,'date'),'http'=>is_wp_error($response)?null:wp_remote_retrieve_response_code($response)],JSON_PRETTY_PRINT).PHP_EOL;}},10,5);
try{Calendly_Bookings\Modules\CB_API::instance()->query_event_type_available_times($uuid);}catch(Throwable $error){}
