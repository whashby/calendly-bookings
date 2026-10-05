<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,4).'/wp-load.php';
$output=['recent_orders_reviewed'=>0,'orders_with_stored_scheduling_response'=>0,'remote_invitees_checked'=>0,'remote_invitee_matches'=>0,'stored_response_answer_matches'=>0,'remote_read_failures'=>0];
foreach(wc_get_orders(['limit'=>50,'orderby'=>'date','order'=>'DESC'])as$order){$output['recent_orders_reviewed']++;$stored=json_decode((string)$order->get_meta('_cb_calendly_create_response',true),true);if(!is_array($stored)||empty($stored['uri']))continue;$output['orders_with_stored_scheduling_response']++;if($output['remote_invitees_checked']>=5)continue;$remote=Calendly_Bookings\Modules\CB_API::instance()->get_resource($stored['uri']);if(empty($remote['resource'])){$output['remote_read_failures']++;continue;}$resource=$remote['resource'];$output['remote_invitees_checked']++;if(($resource['uri']??null)===$stored['uri'])$output['remote_invitee_matches']++;if(($resource['questions_and_answers']??[])===($stored['questions_and_answers']??[]))$output['stored_response_answer_matches']++;}
echo wp_json_encode($output,JSON_PRETTY_PRINT).PHP_EOL;
