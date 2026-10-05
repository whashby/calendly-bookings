<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,4).'/wp-load.php';
$rows=[];foreach([10116,10117,10118,10119]as$id){$order=wc_get_order($id);if(!$order){$rows[]=['order'=>$id,'exists'=>false];continue;}$rows[]=['order'=>$id,'exists'=>true,'status'=>$order->get_status(),'total'=>$order->get_total(),'payment_method'=>$order->get_payment_method(),'billing_email_valid'=>(bool)is_email($order->get_billing_email()),'calendly_status'=>$order->get_meta('_cb_calendly_booking_status',true),'calendly_error'=>preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i','[email]',$order->get_meta('_cb_calendly_booking_error',true)),'calendly_response_recorded'=>(bool)$order->get_meta('_cb_calendly_create_response',true)];}
echo wp_json_encode(['orders'=>$rows,'admin_email_valid'=>(bool)is_email(get_option('admin_email'))],JSON_PRETTY_PRINT).PHP_EOL;
