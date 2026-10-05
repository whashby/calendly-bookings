<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,4).'/wp-load.php';
$uuid=$wpdb->get_var("SELECT uuid FROM {$wpdb->prefix}cb_event_types WHERE active=1 LIMIT 1");
// Deliberately invalid past time AND missing invitee email: this cannot create a booking.
$payload=['event_type'=>'https://api.calendly.com/event_types/'.$uuid,'start_time'=>'2000-01-01T00:00:00Z','invitee'=>['name'=>'Validation fixture','timezone'=>'America/Barbados'],'tracking'=>['utm_source'=>'wordpress','utm_campaign'=>'hierlife','utm_content'=>'validation-fixture','utm_medium'=>'woocommerce','utm_term'=>null,'salesforce_uuid'=>null]];
$result=Calendly_Bookings\Modules\CB_API::instance()->create_invitee($payload);
$trackingerrors=[];foreach(($result['body']['details']??[])as$detail){if(str_starts_with($detail['parameter']??'','tracking'))$trackingerrors[]=$detail;}
echo wp_json_encode(['status'=>$result['status']??0,'rejected'=>!empty($result['error']),'tracking_errors'=>$trackingerrors,'booking_created'=>!empty($result['resource'])],JSON_PRETTY_PRINT).PHP_EOL;
if(empty($result['error']) || !empty($result['resource']) || $trackingerrors)exit(1);
