<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,4).'/wp-load.php';
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);if($admins)wp_set_current_user((int)$admins[0]);
$response=rest_get_server()->dispatch(new WP_REST_Request('GET','/calendly-bookings/v1/dashboard/health'));
echo wp_json_encode(['status'=>$response->get_status(),'data'=>$response->get_data()],JSON_PRETTY_PRINT).PHP_EOL;
