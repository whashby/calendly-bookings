<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,4).'/wp-load.php';
use Calendly_Bookings\Modules\CB_API;
use Calendly_Bookings\CB_Constants;
use Calendly_Bookings\Modules\CB_Sync_Status;
$result=CB_API::instance()->sync(get_option(CB_Constants::OPT_MIN_START_DATE)?:null,true);
$output=['success'=>$result['success'],'errors'=>$result['errors'],'last_sync'=>$result['last_sync'],'master'=>CB_Sync_Status::state('master'),'availability'=>CB_Sync_Status::state('availability')];
echo wp_json_encode($output,JSON_PRETTY_PRINT).PHP_EOL;
exit($result['success']?0:1);
