<?php
namespace Calendly_Bookings\Modules;
use Calendly_Bookings\CB_Constants;
if(!defined('ABSPATH'))exit;
final class CB_Plugin{
    public static function init():void{add_action('admin_init',[__CLASS__,'register_settings']);}
    public static function register_settings():void{register_setting(CB_Constants::OPT_GROUP,CB_Constants::OPT_SYNC_INTERVAL);}
}
