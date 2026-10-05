<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,4).'/wp-load.php';
use Calendly_Bookings\Modules\CB_Customer_Time as Time;
use Calendly_Bookings\Modules\CB_Checkout;
use Calendly_Bookings\Modules\CB_Email;
use Calendly_Bookings\Modules\CB_Booking_Reconciliation;
$passed=0;function check_time($ok,$name){global $passed;if(!$ok)throw new RuntimeException($name);$passed++;echo 'PASS: '.$name.PHP_EOL;}
$zone=new DateTimeZone('America/Los_Angeles');$iso='2026-10-06T01:30:00Z';
check_time(Time::format($iso,$zone,'Y-m-d g:i a')==='2026-10-05 6:30 pm','UTC converts to customer local time and previous date');
check_time(Time::format('2026-01-06T01:30:00Z',$zone,'g:i a')==='5:30 pm','Winter daylight-saving offset is respected');
check_time(Time::valid('America/Barbados')==='America/Barbados'&&Time::valid('invalid/zone')==='','Invalid customer timezone is rejected');
$order=new WC_Order();$order->update_meta_data('_cb_timezone','America/Los_Angeles');$order->update_meta_data('_cb_meeting_start_iso',$iso);
$details=CB_Checkout::meeting_details($order);check_time(str_contains($details['Meeting date and time'],'6:30 pm')&&str_contains($details['Meeting date and time'],'America/Los_Angeles'),'Order and email details use saved customer timezone');
$cart=['cb_timezone'=>'America/Los_Angeles','cb_meeting_start_iso'=>$iso,'cb_meeting_time'=>$iso,'cb_meeting_date'=>'2026-10-06'];$rows=CB_Checkout::display_cart_item_data([],$cart);check_time(count($rows)===1&&!str_contains($rows[0]['value'],$iso)&&str_contains($rows[0]['value'],'6:30 pm'),'Cart hides raw UTC and redundant UTC date');
$item=new WC_Order_Item_Product();CB_Checkout::save_booking_item_meta($item,'fixture',array_merge($cart,['cb_event_uuid'=>'12345678-1234-1234-1234-123456789abc']),$order);$order->add_item($item);$oldpost=$_POST;$_POST=[];CB_Checkout::save_order_meta($order,[]);$_POST=$oldpost;
check_time($order->get_meta('_cb_timezone',true)==='America/Los_Angeles','Timezone survives line-item and order persistence');
$method=new ReflectionMethod(CB_Booking_Reconciliation::class,'build_payload');$payload=$method->invoke(null,$order,[],'12345678-1234-1234-1234-123456789abc',$iso,'Fixture','Customer','fixture@example.invalid');check_time($payload['invitee']['timezone']==='America/Los_Angeles'&&$payload['start_time']===$iso,'API uses customer timezone while retaining exact UTC slot');
$order->update_meta_data('_cb_meeting_confirmation_token','fixture-token');$emailmethod=new ReflectionMethod(CB_Email::class,'context_from_order');$ctx=$emailmethod->invoke(null,$order,['payload'=>['event'=>['timezone'=>'UTC','start_time'=>$iso],'invitee'=>['timezone'=>'America/Los_Angeles']]]);check_time($ctx['timezone']==='America/Los_Angeles'&&str_contains($ctx['time'],'6:30'),'Email ignores host/event timezone and uses invitee timezone');
$oldcookie=$_COOKIE;$_COOKIE['cb_timezone']='Asia/Tokyo';check_time(Time::viewer_timezone()->getName()==='Asia/Tokyo','Account viewer timezone is validated from browser cookie');$_COOKIE=$oldcookie;
check_time(str_contains(Time::html('2026-10-06 01:30:00'),'2026-10-06T01:30:00+00:00'),'Account SQL datetime remains explicitly UTC for browser conversion');
echo $passed.' timezone checks passed.'.PHP_EOL;
