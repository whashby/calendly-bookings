<?php
if(PHP_SAPI!=='cli' && (!defined('CB_CHECKOUT_FIXTURE_HTTP') || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true))){http_response_code(404);exit;}
require dirname(__DIR__,4).'/wp-load.php';
use Calendly_Bookings\Modules\CB_Checkout;
use Calendly_Bookings\Modules\CB_Booking_Reconciliation as Worker;
use Calendly_Bookings\Modules\CB_Logger;
$checks=[];$orders=[];$users=[];$mailcount=0;$networkcount=0;$cachekey=null;$oldcache=false;
function verify_checkout($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;}
$blockmail=function($pre,$args)use(&$mailcount){$mailcount++;return true;};
$blockhttp=function($pre,$args,$url)use(&$networkcount){$networkcount++;return new WP_Error('fixture_blocked','Network disabled during checkout regression.');};
add_filter('pre_wp_mail',$blockmail,1,2);add_filter('pre_http_request',$blockhttp,1,3);
set_error_handler(function($severity,$message,$file,$line){if(!(error_reporting()&$severity))return false;throw new ErrorException($message,0,$severity,$file,$line);});
$result=[];
try{
 $type=$wpdb->get_row("SELECT uuid,product_id FROM {$wpdb->prefix}cb_event_types WHERE active=1 AND product_id>0 LIMIT 1",ARRAY_A);if(!$type)throw new RuntimeException('No mapped product.');
 $product=wc_get_product($type['product_id']);$uuid=(string)$product->get_meta('_cb_event_uuid',true)?:$type['uuid'];
 $definition=['uri'=>'https://api.calendly.com/event_types/'.$uuid,'locations'=>[['kind'=>'zoom_conference']],'custom_questions'=>[]];
 $cachekey='cb_event_definition_'.md5($uuid);$oldcache=get_transient($cachekey);set_transient($cachekey,$definition,300);
 WC()->session=new WC_Session_Handler();WC()->session->init();WC()->customer=new WC_Customer(0,true);WC()->cart=new WC_Cart();
 CB_Logger::debug('Checkout HTTP regression: structured logging succeeds.');verify_checkout(CB_Checkout::order_has_meeting(null)===false,'Invalid-order diagnostics return safely');
 foreach([0,25]as$total){
  WC()->cart->empty_cart();$product=wc_get_product($type['product_id']);$product->set_virtual(true);$product->set_price($total);
  $start=gmdate('Y-m-d\TH:i:s\Z',time()+86400*20);$text="Checkout notes — café\nC:\\notes\\fixture";
  $_POST=wp_slash(['cb_event_uuid'=>$uuid,'cb_meeting_start_iso'=>$start,'cb_meeting_location'=>Calendly_Bookings\Modules\CB_Frontend::location_key($definition['locations'][0],0),'cb_prep_notes'=>$text]);
  $booking=CB_Checkout::capture_form_data([],$product->get_id(),0);
  $values=array_merge($booking,['product_id'=>$product->get_id(),'variation_id'=>0,'variation'=>[],'quantity'=>1,'data'=>$product,'data_hash'=>wc_get_cart_item_data_hash($product),'line_subtotal'=>$total,'line_total'=>$total,'line_subtotal_tax'=>0,'line_tax'=>0,'line_tax_data'=>['subtotal'=>[],'total'=>[]]]);
  WC()->cart->cart_contents=['checkout-fixture'=>$values];WC()->cart->calculate_totals();
  $email='checkout-fixture-'.wp_generate_password(10,false,false).'@example.invalid';$data=['billing_first_name'=>'Checkout','billing_last_name'=>'Fixture','billing_email'=>$email,'billing_country'=>'BB','billing_address_1'=>'Fixture address','billing_city'=>'Bridgetown','billing_phone'=>'+12465550123','payment_method'=>'','ship_to_different_address'=>false,'order_comments'=>$text];
  $_POST=[];$id=WC()->checkout()->create_order($data);if(is_wp_error($id))throw new RuntimeException($id->get_error_message());$orders[]=(int)$id;$order=wc_get_order($id);
  verify_checkout($order instanceof WC_Order,($total?'Paid':'Free').' checkout creates a WooCommerce order');
  verify_checkout($order->get_meta('_cb_meeting_start_iso',true)===$start,'Checkout core promotes selected slot from line item');
  verify_checkout($order->get_meta('_cb_prep_notes',true)===$text,'Checkout core preserves multiline booking notes');
  verify_checkout($order->get_customer_id()===0,'Guest order is created without a premature account attachment');
  do_action('woocommerce_checkout_order_processed',$id,$data,$order);
  $args=['order_id'=>(int)$id];
  if($total){verify_checkout(!as_has_scheduled_action(Worker::ACTION_HOOK,$args,'calendly-bookings'),'Unpaid checkout does not schedule a Calendly booking');as_unschedule_all_actions(Worker::ACTION_HOOK,$args,'calendly-bookings');$order->payment_complete('fixture-local-reference');}
  else {$order->payment_complete();}
  $order=wc_get_order($id);$user=get_user_by('email',$email);if($user)$users[]=(int)$user->ID;
  verify_checkout($order->get_customer_id()>0,'Payment hook attaches guest meeting order without PHP reference warnings');
  verify_checkout(as_has_scheduled_action(Worker::ACTION_HOOK,$args,'calendly-bookings'),'Eligible meeting order queues one background booking');
 }
 $ordinary=new WC_Order();$ordinary->set_billing_email('ordinary-fixture@example.invalid');$ordinary->set_total(0);$ordinary->save();$orders[]=$ordinary->get_id();Worker::queue_free_order($ordinary->get_id(),[],$ordinary);verify_checkout(!as_has_scheduled_action(Worker::ACTION_HOOK,['order_id'=>$ordinary->get_id()],'calendly-bookings'),'Ordinary free orders do not queue Calendly work');verify_checkout(CB_Checkout::attach_order_to_account($ordinary->get_id())===0,'Ordinary checkout does not create a meeting account');
 $result=['success'=>true,'checks'=>count($checks),'labels'=>$checks,'mail_intercepted'=>$mailcount,'external_requests'=>$networkcount];
}catch(Throwable $error){$result=['success'=>false,'checks'=>count($checks),'error'=>$error->getMessage(),'file'=>basename($error->getFile()),'line'=>$error->getLine()];}
finally{
 restore_error_handler();foreach($orders as$id){as_unschedule_all_actions(Worker::ACTION_HOOK,['order_id'=>$id],'calendly-bookings');wp_clear_scheduled_hook('cb_booking_process_order_fallback',[$id]);$order=wc_get_order($id);if($order)$order->delete(true);}
 if($users){require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$id)wp_delete_user($id);}
 if(WC()->cart)WC()->cart->empty_cart();if(WC()->session)WC()->session->destroy_session();
 if($cachekey){if($oldcache===false)delete_transient($cachekey);else set_transient($cachekey,$oldcache,300);}
 remove_filter('pre_wp_mail',$blockmail,1);remove_filter('pre_http_request',$blockhttp,1);
}
if(PHP_SAPI!=='cli')header('Content-Type: application/json');echo wp_json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;if(PHP_SAPI==='cli')exit($result['success']?0:1);
